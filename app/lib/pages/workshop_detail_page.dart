import 'dart:io';
import 'dart:async';
import 'package:flutter/material.dart';
import 'package:url_launcher/url_launcher.dart';

import '../services/api.dart';
import '../widgets/avatar.dart';
import 'public_profile_page.dart';

class WorkshopDetailPage extends StatefulWidget {
  final String? workshopId;
  const WorkshopDetailPage({super.key, this.workshopId});

  @override
  State<WorkshopDetailPage> createState() => _WorkshopDetailPageState();
}

class _WorkshopDetailPageState extends State<WorkshopDetailPage> {
  bool _loading = true;
  String? _err;
  Map<String, dynamic> _w = {};

  // Why: avoid exposing internal errors; keep copy user-friendly
  String _friendly(Object error, {String? fallback}) {
    final fb = fallback ?? 'Something went wrong. Please try again.';
    if (error is SocketException) return 'No internet connection.';
    if (error is HttpException) return 'Unable to reach the server.';
    final s = error.toString().toLowerCase();
    if (s.contains('timeout')) return 'Request timed out. Please retry.';
    if (s.contains('unauthorized') || s.contains('401')) return 'Please sign in again.';
    if (s.contains('forbidden') || s.contains('403')) return 'You don’t have permission to do that.';
    if (s.contains('server') || s.contains('500') || s.contains('502') || s.contains('503') || s.contains('504')) {
      return 'Server issue. Please try again later.';
    }
    return fb;
  }

  String get _wid {
    final args = ModalRoute.of(context)?.settings.arguments;
    if (widget.workshopId != null && widget.workshopId!.isNotEmpty) return widget.workshopId!;
    if (args is Map && args['id'] != null) return args['id'].toString();
    return '';
  }

  int get _capacity => (_w['capacity'] as num?)?.toInt() ?? 0;
  int get _claimed => ((_w['counts'] as Map?)?['claims'] as num?)?.toInt() ?? 0;
  bool get _isFull => _capacity > 0 && _claimed >= _capacity;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) => _load());
  }

  Future<void> _load() async {
    final id = _wid;
    if (id.isEmpty) {
      setState(() {
        _loading = false;
        _err = 'Workshop not found.';
      });
      return;
    }

    setState(() {
      _loading = true;
      _err = null;
    });

    try {
      final res = await ApiService.get('workshop_detail.php?id=$id');
      if (!mounted) return;
      if (res['ok'] == true && res['workshop'] is Map) {
        setState(() {
          _w = (res['workshop'] as Map).cast<String, dynamic>();
          _loading = false;
        });
      } else {
        setState(() {
          _loading = false;
          _err = 'Couldn’t load workshop details.';
        });
      }
    } catch (e) {
      if (!mounted) return;
      setState(() {
        _loading = false;
        _err = _friendly(e, fallback: 'Couldn’t load workshop details.');
      });
    }
  }

  // ---- helpers -------------------------------------------------------------

  Future<void> _openUrl(String raw) async {
    final uri = Uri.tryParse(raw);
    if (uri == null) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('No valid join link provided.')),
      );
      return;
    }
    try {
      final ok = await launchUrl(uri, mode: LaunchMode.externalApplication, webOnlyWindowName: '_blank');
      if (!ok) {
        await launchUrl(uri, webOnlyWindowName: '_blank');
      }
    } catch (e) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(_friendly(e, fallback: 'Couldn’t open the link.'))),
      );
    }
  }

  String _fmtDateTime(String? iso) {
    if (iso == null || iso.isEmpty) return 'TBD';
    final dt = DateTime.tryParse(iso)?.toLocal();
    if (dt == null) return 'TBD';
    String two(int n) => n < 10 ? '0$n' : '$n';
    final h = dt.hour % 12 == 0 ? 12 : dt.hour % 12;
    final ampm = dt.hour >= 12 ? 'PM' : 'AM';
    return '${dt.month}/${dt.day}/${dt.year}  •  $h:${two(dt.minute)} $ampm';
  }

  String? _createdRaw() {
    for (final k in ['created_at', 'created', 'createdAt']) {
      final v = _w[k];
      if (v != null && v.toString().isNotEmpty) return v.toString();
    }
    return null;
  }

  String _fmtDuration(String? s, String? e) {
    final sd = DateTime.tryParse((s ?? '').toString());
    final ed = DateTime.tryParse((e ?? '').toString());
    if (sd == null || ed == null) return '—';
    final mins = ed.difference(sd).inMinutes;
    if (mins <= 0) return '—';
    final h = mins ~/ 60, m = mins % 60;
    if (h > 0 && m > 0) return '${h}h ${m}m';
    if (h > 0) return '${h}h';
    return '${m}m';
  }

  Widget _infoTile(IconData icon, String label, String value) {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 6),
      child: Row(
        children: [
          Icon(icon, size: 20),
          const SizedBox(width: 10),
          Text(label, style: const TextStyle(fontWeight: FontWeight.w600)),
          const Spacer(),
          Text(value, style: const TextStyle(color: Colors.black87)),
        ],
      ),
    );
  }

  // ---- actions -------------------------------------------------------------

  Future<void> _joinNow() async {
    final wid = _w['id']?.toString() ?? '';
    if (wid.isEmpty) return;

    String url = '';
    try {
      final res = await ApiService.postForm('workshop_access.php', {'workshop_id': wid});
      if (!mounted) return;

      if (res['ok'] == true) {
        final counts = (_w['counts'] as Map?)?.cast<String, dynamic>() ?? {};
        setState(() {
          _w = {..._w, 'counts': {...counts, 'claims': (_claimed + 1)}};
        });
        url = (res['join_url'] ?? '').toString();
      }
    } catch (_) {
      // ignore; we’ll try fallbacks below
    }

    url = url.isNotEmpty
        ? url
        : (_w['zoom_join_url'] ?? _w['zoom_link'] ?? '').toString();

    if (url.isNotEmpty) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(content: Text('Opening meeting…')),
        );
      }
      await _openUrl(url);
    } else {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Join link is unavailable right now.')),
      );
    }
  }

  // ---- UI ------------------------------------------------------------------

  @override
  Widget build(BuildContext context) {
    if (_loading) {
      return const Scaffold(body: Center(child: CircularProgressIndicator()));
    }
    if (_err != null) {
      return Scaffold(
        appBar: AppBar(title: const Text('Workshop')),
        body: Center(
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              const Icon(Icons.info_outline),
              const SizedBox(height: 8),
              Text(_err!, textAlign: TextAlign.center),
              const SizedBox(height: 8),
              OutlinedButton(onPressed: _load, child: const Text('Retry')),
            ],
          ),
        ),
      );
    }

    final hostAny = _w['host'];
    final host = (hostAny is Map) ? hostAny.cast<String, dynamic>() : const <String, dynamic>{};

    final title = (_w['title'] ?? '').toString();
    final desc = (_w['description'] ?? '').toString();
    final startsAt = (_w['starts_at'] ?? '').toString();
    final endsAt = (_w['ends_at'] ?? '').toString();

    final hostId = (host['id'] ?? '').toString();
    final canViewHost = hostId.isNotEmpty;

    return Scaffold(
      appBar: AppBar(title: Text(title.isEmpty ? 'Workshop' : title)),
      body: ListView(
        padding: const EdgeInsets.fromLTRB(16, 16, 16, 28),
        children: [
          Text(title, style: const TextStyle(fontSize: 20, fontWeight: FontWeight.w800)),
          const SizedBox(height: 12),

          Row(
            children: [
              Avatar(url: host['avatar_url']?.toString() ?? '', size: 44),
              const SizedBox(width: 10),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      (host['display_name'] ?? '').toString(),
                      style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 16),
                    ),
                    if ((host['username'] ?? '').toString().isNotEmpty)
                      Text('@${host['username']}', style: const TextStyle(color: Colors.black54)),
                  ],
                ),
              ),
              TextButton(
                onPressed: canViewHost
                    ? () => Navigator.push(
                          context,
                          MaterialPageRoute(builder: (_) => PublicProfilePage(userId: hostId)),
                        )
                    : null,
                child: const Text('View'),
              ),
            ],
          ),

          const SizedBox(height: 16),

          if (desc.isNotEmpty) ...[
            Text(desc),
            const SizedBox(height: 16),
          ],

          Card(
            elevation: 0,
            shape: RoundedRectangleBorder(
              borderRadius: BorderRadius.circular(12),
              side: const BorderSide(color: Colors.black12),
            ),
            child: Padding(
              padding: const EdgeInsets.fromLTRB(12, 12, 12, 6),
              child: Column(
                children: [
                  _infoTile(Icons.event, 'Created', _fmtDateTime(_createdRaw())),
                  _infoTile(Icons.timelapse, 'Duration', _fmtDuration(startsAt, endsAt)),
                ],
              ),
            ),
          ),

          const SizedBox(height: 20),

          Center(
            child: SizedBox(
              width: 260,
              height: 44,
              child: FilledButton.icon(
                onPressed: _isFull ? null : _joinNow,
                icon: Icon(_isFull ? Icons.event_busy : Icons.rocket_launch),
                label: Text(_isFull ? 'Full' : 'Join'),
              ),
            ),
          ),
        ],
      ),
    );
  }
}
