import 'dart:io';
import 'dart:math' as math;
import 'package:flutter/material.dart';
import '../services/api.dart';

class AdminReportsPage extends StatefulWidget {
  const AdminReportsPage({super.key});
  @override
  State<AdminReportsPage> createState() => _AdminReportsPageState();
}

class _AdminReportsPageState extends State<AdminReportsPage> {
  String _range = '30d'; // 7d | 30d | 90d
  bool _loading = true;
  String? _err;

  Map<String, num> _kpis = const {
    'active_users': 0,
    'new_users': 0,
    'posts': 0,
    'comments': 0,
    'likes': 0,
    'workshops': 0,
    'attendance': 0,
    'revenue_cents': 0,
  };

  List<Map<String, dynamic>> _series = [];
  String _chartMetric = 'posts';

  static const _metricLabels = {
    'active_users': 'Active Users',
    'new_users': 'New Users',
    'posts': 'Posts',
    'comments': 'Comments',
    'likes': 'Likes',
    'attendance': 'Attendance',
    'revenue_cents': 'Revenue',
  };

  @override
  void initState() {
    super.initState();
    _loadAll();
  }

  // User-safe error mapping (why: avoid dev-facing messages).
  String _friendly(Object error, {String? fallback}) {
    final fb = fallback ?? 'Something went wrong. Please try again.';
    if (error is SocketException) return 'No internet connection.';
    if (error is HttpException) return 'Unable to reach the server.';
    final msg = error.toString().toLowerCase();
    if (msg.contains('timeout')) return 'Request timed out. Please retry.';
    if (msg.contains('unauthorized') || msg.contains('401')) return 'You need to sign in again.';
    if (msg.contains('forbidden') || msg.contains('403')) return 'You don’t have permission to do that.';
    if (msg.contains('404')) return 'Not found.';
    if (msg.contains('500') || msg.contains('502') || msg.contains('503') || msg.contains('504')) {
      return 'Server issue. Please try again later.';
    }
    return fb;
  }

  num _toNum(dynamic v) {
    if (v is num) return v;
    if (v == null) return 0;
    final n = num.tryParse(v.toString());
    return n ?? 0;
  }

  T? _asMap<T extends Map>(dynamic v) => (v is T) ? v : null;
  List _asList(dynamic v) => (v is List) ? v : const [];

  Map<String, dynamic> _normalizeDay(Map<String, dynamic> d) {
    num getAny(List<String> keys) {
      for (final k in keys) {
        if (d.containsKey(k)) return _toNum(d[k]);
      }
      return 0;
    }

    return {
      'date': (d['date'] ?? d['day'] ?? d['d'] ?? '').toString(),
      'active_users': getAny(['active_users', 'active', 'active_count']),
      'new_users': getAny(['new_users', 'signups', 'registrations']),
      'posts': getAny(['posts', 'post_count', 'posts_count']),
      'comments': getAny(['comments', 'comments_count']),
      'likes': getAny(['likes', 'likes_count']),
      'attendance': getAny(['attendance', 'attendance_count', 'checkins', 'check_ins']),
      'revenue_cents': getAny(['revenue_cents', 'revenueCents']),
    };
  }

  // Handles both {date,metric:value} and {items:[{date,value}], metric:'posts'}
  List<Map<String, dynamic>> _coerceSeries(dynamic ts) {
    final list = (ts is Map && ts['items'] is List) ? (ts['items'] as List) : const [];
    if (list.isEmpty) return const [];

    final first = (list.first is Map) ? (list.first as Map).cast<String, dynamic>() : <String, dynamic>{};
    final hasKnownMetricKey = first.keys.any((k) =>
        k == 'posts' ||
        k == 'comments' ||
        k == 'likes' ||
        k == 'active_users' ||
        k == 'new_users' ||
        k == 'attendance' ||
        k == 'revenue_cents');

    if (hasKnownMetricKey) {
      return list.map((e) => (e as Map).cast<String, dynamic>()).toList();
    }

    final metric = (ts is Map && ts['metric'] is String && ts['metric'].toString().isNotEmpty)
        ? ts['metric'].toString()
        : _chartMetric;

    return list.map<Map<String, dynamic>>((e) {
      final m = (e as Map).cast<String, dynamic>();
      final date = (m['date'] ?? m['day'] ?? m['d'] ?? '').toString();
      final value = _toNum(m['value']);
      return {'date': date, metric: value};
    }).toList();
  }

  // Picks a non-flat metric to avoid an empty-looking chart.
  String _pickBestMetric(List<Map<String, dynamic>> days) {
    const order = [
      'posts',
      'comments',
      'active_users',
      'new_users',
      'likes',
      'attendance',
      'revenue_cents',
    ];
    for (final k in order) {
      final sum = days.fold<num>(0, (a, e) => a + _toNum(e[k]));
      if (sum > 0) return k;
    }
    return 'posts';
  }

  Future<void> _loadAll() async {
    setState(() {
      _loading = true;
      _err = null;
    });

    try {
      final ov = await ApiService.adminAnalyticsOverview(range: _range);
      final ts = await ApiService.adminAnalyticsSeries(range: _range);

      if (!mounted) return;

      final rawMap = _asMap<Map>(ov['kpis']) ?? _asMap<Map>(ov) ?? <String, dynamic>{};
      final k = rawMap.cast<String, dynamic>();

      final coerced = _coerceSeries(ts);
      final days = coerced.map(_normalizeDay).toList();

      setState(() {
        _kpis = {
          'active_users': _toNum(k['active_users']),
          'new_users': _toNum(k['new_users']),
          'posts': _toNum(k['posts']),
          'comments': _toNum(k['comments']),
          'likes': _toNum(k['likes']),
          'workshops': _toNum(k['workshops']),
          'attendance': _toNum(k['attendance']),
          'revenue_cents': _toNum(k['revenue_cents']),
        };

        _series = days;

        // Prefer API metric if given; otherwise choose a non-flat one (why: better default UX).
        if (ts is Map && ts['metric'] is String && ts['metric'].toString().isNotEmpty) {
          _chartMetric = ts['metric'].toString();
        } else {
          _chartMetric = _pickBestMetric(days);
        }

        _loading = false;
      });
    } catch (e) {
      if (!mounted) return;
      setState(() {
        _loading = false;
        _err = _friendly(e, fallback: 'Failed to load analytics.');
        // Keeping tech detail out of UI intentionally.
      });
    }
  }

  String _fmtMoney(num cents) => '\$${(cents / 100).toStringAsFixed(2)}';

  Widget _rangeChips() {
    const options = ['7d', '30d', '90d'];
    return Wrap(
      spacing: 8,
      children: options.map((r) {
        final sel = _range == r;
        return ChoiceChip(
          label: Text(r.toUpperCase()),
          selected: sel,
          onSelected: (_) {
            setState(() => _range = r);
            _loadAll();
          },
        );
      }).toList(),
    );
  }

  Widget _kpiCard(String label, String value, {IconData? icon}) {
    return Card(
      elevation: 0,
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(12),
        side: const BorderSide(color: Colors.black12),
      ),
      child: Padding(
        padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
        child: Row(
          children: [
            if (icon != null) ...[
              Icon(icon),
              const SizedBox(width: 10),
            ],
            Expanded(
              child: Column(
                mainAxisSize: MainAxisSize.min,
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(value, style: const TextStyle(fontSize: 17, fontWeight: FontWeight.w800)),
                  const SizedBox(height: 2),
                  Text(label, style: const TextStyle(fontSize: 12, color: Colors.black54, height: 1.1)),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }

  List<double> _valuesFor(String key) {
    final vals = <double>[];
    for (final d in _series) {
      vals.add(_toNum(d[key]).toDouble());
    }
    return vals;
  }

  Widget _metricChips() {
    final keys = _metricLabels.keys.toList();
    return SingleChildScrollView(
      scrollDirection: Axis.horizontal,
      child: Row(
        children: keys.map((k) {
          final sel = _chartMetric == k;
          return Padding(
            padding: const EdgeInsets.only(right: 8),
            child: ChoiceChip(
              label: Text(_metricLabels[k]!),
              selected: sel,
              onSelected: (_) => setState(() => _chartMetric = k),
            ),
          );
        }).toList(),
      ),
    );
  }

  Widget _lineChart() {
    final values = _valuesFor(_chartMetric);
    final hasData = values.any((v) => v > 0);

    return Container(
      height: 180,
      decoration: BoxDecoration(
        borderRadius: BorderRadius.circular(12),
        border: Border.all(color: Colors.black12),
        color: Colors.white,
      ),
      child: hasData
          ? CustomPaint(painter: _SimpleLineChartPainter(values: values))
          : const Center(child: Text('No data in this range')),
    );
  }

  @override
  Widget build(BuildContext context) {
    final k = _kpis;

    final body = _err != null
        ? Center(
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                const Icon(Icons.info_outline),
                const SizedBox(height: 8),
                Text(_err!),
                const SizedBox(height: 8),
                OutlinedButton(onPressed: _loadAll, child: const Text('Retry')),
              ],
            ),
          )
        : ListView(
            padding: const EdgeInsets.fromLTRB(16, 16, 16, 24),
            children: [
              Row(
                children: [
                  const Icon(Icons.analytics_outlined),
                  const SizedBox(width: 10),
                  const Expanded(
                    child: Text('Admin Reports', style: TextStyle(fontSize: 20, fontWeight: FontWeight.w800)),
                  ),
                  IconButton(onPressed: _loadAll, icon: const Icon(Icons.refresh)),
                ],
              ),
              const SizedBox(height: 8),
              _rangeChips(),
              const SizedBox(height: 12),
              GridView(
                shrinkWrap: true,
                physics: const NeverScrollableScrollPhysics(),
                gridDelegate: const SliverGridDelegateWithFixedCrossAxisCount(
                  crossAxisCount: 2,
                  mainAxisExtent: 90,
                  crossAxisSpacing: 10,
                  mainAxisSpacing: 10,
                ),
                children: [
                  _kpiCard('Active Users', '${k['active_users']}', icon: Icons.people_alt_outlined),
                  _kpiCard('New Users', '${k['new_users']}', icon: Icons.person_add_alt_1_outlined),
                  _kpiCard('Posts', '${k['posts']}', icon: Icons.photo_library_outlined),
                  _kpiCard('Comments', '${k['comments']}', icon: Icons.mode_comment_outlined),
                  _kpiCard('Likes', '${k['likes']}', icon: Icons.favorite_border),
                  _kpiCard('Workshops', '${k['workshops']}', icon: Icons.live_tv_outlined),
                  _kpiCard('Attendance', '${k['attendance']}', icon: Icons.qr_code_2_outlined),
                  _kpiCard('Revenue', _fmtMoney(k['revenue_cents'] ?? 0), icon: Icons.payments_outlined),
                ],
              ),
              const SizedBox(height: 16),
              const Text('Daily trends', style: TextStyle(fontWeight: FontWeight.w800)),
              const SizedBox(height: 8),
              _metricChips(),
              const SizedBox(height: 8),
              _lineChart(),
            ],
          );

    return Scaffold(
      appBar: AppBar(
        leading: Navigator.of(context).canPop()
            ? IconButton(
                icon: const Icon(Icons.arrow_back),
                onPressed: () => Navigator.of(context).pop(),
              )
            : null,
        title: const Text('Admin Reports'),
        actions: [
          IconButton(
            icon: const Icon(Icons.refresh),
            onPressed: _loadAll,
            tooltip: 'Refresh',
          ),
        ],
      ),
      body: SafeArea(
        child: _loading ? const Center(child: CircularProgressIndicator()) : body,
      ),
    );
  }
}

class _SimpleLineChartPainter extends CustomPainter {
  _SimpleLineChartPainter({required this.values});
  final List<double> values;

  @override
  void paint(Canvas canvas, Size size) {
    final paintAxis = Paint()
      ..color = const Color(0xFFDDDDDD)
      ..strokeWidth = 1;

    final paintLine = Paint()
      ..color = Colors.black
      ..style = PaintingStyle.stroke
      ..strokeWidth = 2;

    const left = 8.0, right = 8.0, top = 8.0, bottom = 18.0;
    final w = size.width - left - right;
    final h = size.height - top - bottom;

    canvas.drawLine(Offset(left, size.height - bottom), Offset(size.width - right, size.height - bottom), paintAxis);
    canvas.drawLine(Offset(left, top), Offset(left, size.height - bottom), paintAxis);

    if (values.isEmpty || w <= 0 || h <= 0) return;

    final maxV = values.reduce(math.max);
    final minV = values.reduce(math.min);
    final span = (maxV - minV).abs() < 1e-6 ? 1.0 : (maxV - minV);

    // Why: stable spacing even with 1 point; avoids division by zero.
    final stepX = w / math.max(1, values.length - 1);
    final path = Path();

    for (var i = 0; i < values.length; i++) {
      final x = left + i * stepX;
      final norm = (values[i] - minV) / span;
      final y = top + (1 - norm) * h;
      if (i == 0) {
        path.moveTo(x, y);
      } else {
        path.lineTo(x, y);
      }
    }
    canvas.drawPath(path, paintLine);
  }

  @override
  bool shouldRepaint(covariant _SimpleLineChartPainter oldDelegate) {
    return oldDelegate.values != values;
  }
}
