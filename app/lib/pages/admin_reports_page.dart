// lib/pages/admin_reports_page.dart
import 'dart:io';
import 'dart:math' as math;
import 'package:flutter/material.dart';
import '../services/api.dart';
import './workshop_page.dart';

class AdminReportsPage extends StatefulWidget {
  const AdminReportsPage({super.key});
  @override
  State<AdminReportsPage> createState() => _AdminReportsPageState();
}

class _AdminReportsPageState extends State<AdminReportsPage> {
  String _range = '30d'; // 7d | 30d | 90d
  bool _loading = true;
  String? _err;

  // Revenue removed
  Map<String, num> _kpis = const {
    'active_users': 0,
    'new_users': 0,
    'posts': 0,
    'comments': 0,
    'likes': 0,
    'workshops': 0,
  };

  int _pendingCount = 0;

  List<Map<String, dynamic>> _series = [];
  String _chartMetric = 'posts';

  static const _metricLabels = {
    'posts': 'Posts',
    'comments': 'Comments',
    'active_users': 'Active Users',
    'new_users': 'New Users',
    'likes': 'Likes',
  };

  @override
  void initState() {
    super.initState();
    _loadAll();
  }

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
    };
  }

  List<Map<String, dynamic>> _coerceSeries(dynamic ts) {
    final list = (ts is Map && ts['items'] is List) ? (ts['items'] as List) : const [];
    if (list.isEmpty) return const [];

    final first = (list.first is Map) ? (list.first as Map).cast<String, dynamic>() : <String, dynamic>{};
    final hasMetric = first.keys.any((k) =>
        k == 'posts' || k == 'comments' || k == 'likes' || k == 'active_users' || k == 'new_users');

    if (hasMetric) {
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

  String _pickBestMetric(List<Map<String, dynamic>> days) {
    const order = ['posts', 'comments', 'active_users', 'new_users', 'likes'];
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
      final results = await Future.wait([
        ApiService.adminAnalyticsOverview(range: _range),
        ApiService.adminAnalyticsSeries(range: _range),
        ApiService.get('admin_pending_list.php?limit=1'),
      ]);
      if (!mounted) return;

      final ov = results[0];
      final ts = results[1];
      final pend = (results[2] is Map) ? (results[2] as Map<String, dynamic>) : const {};

      final rawMap = (ov['kpis'] is Map ? ov['kpis'] : ov) as Map? ?? <String, dynamic>{};
      final k = rawMap.cast<String, dynamic>();

      final coerced = _coerceSeries(ts);
      final days = coerced.map(_normalizeDay).toList();

      final pendingCount = _toNum(pend['total'] ?? ((pend['items'] is List) ? (pend['items'] as List).length : 0)).toInt();

      setState(() {
        _kpis = {
          'active_users': _toNum(k['active_users']),
          'new_users': _toNum(k['new_users']),
          'posts': _toNum(k['posts']),
          'comments': _toNum(k['comments']),
          'likes': _toNum(k['likes']),
          'workshops': _toNum(k['workshops']),
        };
        _pendingCount = pendingCount;
        _series = days;

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
      });
    }
  }

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

  // Tappable only when onTap provided (used for Workshops card)
  Widget _kpiCard(String label, String value, {IconData? icon, VoidCallback? onTap}) {
    final content = Padding(
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
    );

    final card = Card(
      elevation: 0,
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(12),
        side: const BorderSide(color: Colors.black12),
      ),
      child: content,
    );

    return onTap == null
        ? card
        : InkWell(
            borderRadius: BorderRadius.circular(12),
            onTap: onTap,
            child: card,
          );
  }

  List<double> _valuesFor(String key) {
    final vals = <double>[];
    for (final d in _series) {
      vals.add(_toNum(d[key]).toDouble());
    }
    return vals;
  }

  List<String> _dateLabels() {
    final labels = <String>[];
    for (final d in _series) {
      labels.add(_fmtDateLabel(d['date']?.toString() ?? ''));
    }
    return labels;
  }

  String _fmtDateLabel(String raw) {
    final dt = DateTime.tryParse(raw);
    if (dt == null) return raw;
    return '${dt.month}/${dt.day}'; // MM/DD
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

  // Render even when all values are zero; draw date ticks
  Widget _lineChart() {
    final values = _valuesFor(_chartMetric);
    final labels = _dateLabels();
    final hasSeries = _series.isNotEmpty;

    return Container(
      height: 200,
      decoration: BoxDecoration(
        borderRadius: BorderRadius.circular(12),
        border: Border.all(color: Colors.black12),
        color: Colors.white,
      ),
      child: hasSeries
          ? CustomPaint(
              painter: _LineChartPainter(
                values: values.isEmpty ? [0] : values,
                labels: labels,
              ),
            )
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

              // KPI grid (Revenue removed). Workshops card is clickable.
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
                  _kpiCard('New Users', '${k['new_users']}', icon: Icons.person_add_alt_1_outlined),
                  _kpiCard('Active Users', '${k['active_users']}', icon: Icons.people_alt_outlined),
                  _kpiCard('Pending verifications', '$_pendingCount', icon: Icons.verified_outlined),
                  _kpiCard('Likes', '${k['likes']}', icon: Icons.favorite_border),
                  _kpiCard('Comments', '${k['comments']}', icon: Icons.mode_comment_outlined),
                  _kpiCard(
                    'Workshops',
                    '${k['workshops']}',
                    icon: Icons.live_tv_outlined,
                    onTap: () => Navigator.push(context, MaterialPageRoute(builder: (_) => const WorkshopPage())),
                  ),
                  _kpiCard('Posts', '${k['posts']}', icon: Icons.photo_library_outlined),
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
            ? IconButton(icon: const Icon(Icons.arrow_back), onPressed: () => Navigator.of(context).pop())
            : null,
        title: const Text('Admin Reports'),
        actions: [
          IconButton(icon: const Icon(Icons.refresh), onPressed: _loadAll, tooltip: 'Refresh'),
        ],
      ),
      body: SafeArea(
        child: _loading ? const Center(child: CircularProgressIndicator()) : body,
      ),
    );
  }
}

// ---- Chart with date axis labels ----
class _LineChartPainter extends CustomPainter {
  _LineChartPainter({required this.values, required this.labels});
  final List<double> values;
  final List<String> labels;

  @override
  void paint(Canvas canvas, Size size) {
    final paintAxis = Paint()..color = const Color(0xFFDDDDDD)..strokeWidth = 1;
    final paintLine = Paint()..color = Colors.black..style = PaintingStyle.stroke..strokeWidth = 2;

    const left = 8.0, right = 8.0, top = 8.0, bottom = 28.0; // more bottom for labels
    final w = size.width - left - right;
    final h = size.height - top - bottom;

    // axes
    final xAxisY = size.height - bottom;
    canvas.drawLine(Offset(left, xAxisY), Offset(size.width - right, xAxisY), paintAxis);
    canvas.drawLine(Offset(left, top), Offset(left, xAxisY), paintAxis);

    if (values.isEmpty || w <= 0 || h <= 0) return;

    final maxV = values.reduce(math.max);
    final minV = values.reduce(math.min);
    final span = (maxV - minV).abs() < 1e-6 ? 1.0 : (maxV - minV);

    final stepX = w / math.max(1, values.length - 1);
    final path = Path();

    for (var i = 0; i < values.length; i++) {
      final x = left + i * stepX;
      final norm = (values[i] - minV) / span;
      final y = top + (1 - norm) * h;
      if (i == 0) path.moveTo(x, y); else path.lineTo(x, y);
    }
    canvas.drawPath(path, paintLine);

    // X ticks: first, mid, last (+ optional quarter)
    final idxs = _tickIndices(values.length);
    for (final i in idxs) {
      final x = left + i * stepX;
      // tick mark
      canvas.drawLine(Offset(x, xAxisY), Offset(x, xAxisY + 4), paintAxis);
      // label
      final lab = (i >= 0 && i < labels.length) ? labels[i] : '';
      final tp = TextPainter(
        text: TextSpan(text: lab, style: const TextStyle(fontSize: 10, color: Colors.black54)),
        textDirection: TextDirection.ltr,
        maxLines: 1,
        ellipsis: '…',
      )..layout(maxWidth: 60);
      final dx = (x - tp.width / 2).clamp(left, size.width - right - tp.width);
      tp.paint(canvas, Offset(dx.toDouble(), xAxisY + 6));
    }
  }

  List<int> _tickIndices(int n) {
    if (n <= 1) return [0];
    if (n == 2) return [0, 1];
    if (n == 3) return [0, 1, 2];
    final last = n - 1;
    final q1 = (n - 1) ~/ 3;
    final q2 = (2 * (n - 1)) ~/ 3;
    return {0, q1, q2, last}.toList()..sort();
  }

  @override
  bool shouldRepaint(covariant _LineChartPainter old) {
    return old.values != values || old.labels != labels;
    }
}
