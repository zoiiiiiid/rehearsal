// lib/pages/workshop_page.dart
// Workshop hub with user-friendly errors (no dev/internal messages).

import 'dart:async';
import 'dart:io';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:mobile_scanner/mobile_scanner.dart';
import 'package:url_launcher/url_launcher.dart';

import '../services/api.dart';
import '../widgets/avatar.dart';
import 'public_profile_page.dart';

const Map<String, String> _kSkillLabels = {
  'all': 'All',
  'dj': 'DJ',
  'singer': 'Singer',
  'guitarist': 'Guitarist',
  'drummer': 'Drummer',
  'bassist': 'Bassist',
  'keyboardist': 'Keyboardist',
  'dancer': 'Dancer',
  'other': 'Other',
};

/// Why: one place to convert technical errors to human messages.
String _friendlyError(Object error, {String? fallback}) {
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

/// Build an absolute URL if user scanned a relative path.
String _resolveUrl(String raw) {
  final u = raw.trim();
  if (u.isEmpty) return '';
  if (u.startsWith('http://') || u.startsWith('https://')) return u;
  final base = Uri.parse(ApiService.baseUrl);
  final origin =
      '${base.scheme}://${base.host}${(base.hasPort && base.port != 80 && base.port != 443) ? ':${base.port}' : ''}';
  if (u.startsWith('/')) return '$origin$u';
  return '$origin/$u';
}

class WorkshopPage extends StatefulWidget {
  const WorkshopPage({super.key});
  @override
  State<WorkshopPage> createState() => _WorkshopPageState();
}

class _WorkshopPageState extends State<WorkshopPage> {
  bool _loading = true;
  String? _err;

  List<Map<String, dynamic>> _ongoing = [];
  List<Map<String, dynamic>> _artists = [];

  String _skill = 'all';

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() {
      _loading = true;
      _err = null;
    });

    final skillParam = _skill == 'all' ? '' : '&skill=${Uri.encodeQueryComponent(_skill)}';

    try {
      final results = await Future.wait([
        ApiService.get('workshops_list.php?status=ongoing&page=1&limit=20'),
        ApiService.get('spotlight_leaderboard.php?days=30&limit=12$skillParam'),
      ]);

      if (!mounted) return;
      final w = results[0];
      final a = results[1];

      final okW = (w['ok'] == true || w['items'] is List);
      final okA = (a['ok'] == true || a['items'] is List);

      if (okW && okA) {
        _ongoing = (w['items'] as List? ?? const [])
            .map((e) => (e as Map).cast<String, dynamic>())
            .toList();
        _artists = (a['items'] as List? ?? const [])
            .map((e) => (e as Map).cast<String, dynamic>())
            .toList();
        setState(() => _loading = false);
      } else {
        setState(() {
          _loading = false;
          _err = 'Couldn’t load workshops right now.';
        });
      }
    } catch (e) {
      if (!mounted) return;
      setState(() {
        _loading = false;
        _err = _friendlyError(e, fallback: 'Couldn’t load workshops right now.');
      });
    }
  }

  Future<void> _changeSkill(String s) async {
    if (s == _skill) return;
    setState(() => _skill = s);
    _load();
  }

  Future<void> _openProfile(String userId) async {
    if (userId.isEmpty) return;
    unawaited(Navigator.push(
      context,
      MaterialPageRoute(builder: (_) => PublicProfilePage(userId: userId)),
    ));
  }

  String _fmtStart(dynamic iso, {bool withDate = true}) {
    final s = iso?.toString() ?? '';
    final dt = DateTime.tryParse(s)?.toLocal();
    if (dt == null) return '';
    String two(int n) => n < 10 ? '0$n' : '$n';
    final h = dt.hour % 12 == 0 ? 12 : dt.hour % 12;
    final ampm = dt.hour >= 12 ? 'PM' : 'AM';
    return withDate ? '${dt.month}/${dt.day} • $h:${two(dt.minute)} $ampm' : '$h:${two(dt.minute)} $ampm';
  }

  Future<void> _openUrl(String raw) async {
    final resolved = _resolveUrl(raw);
    final uri = Uri.tryParse(resolved);
    if (uri == null) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(const SnackBar(content: Text('Invalid link.')));
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
        SnackBar(content: Text(_friendlyError(e, fallback: 'Couldn’t open the link.'))),
      );
    }
  }

  Future<void> _scanQr() async {
    final res = await showModalBottomSheet<Map<String, String>>(
      context: context,
      isScrollControlled: true,
      backgroundColor: Colors.black,
      builder: (_) => const _QuickScanSheet(),
    );
    if (!mounted) return;

    final val = res?['value'] ?? '';
    if (val.isEmpty) return;

    if (val.startsWith('http://') || val.startsWith('https://') || val.startsWith('/')) {
      await _openUrl(val);
    } else {
      await Clipboard.setData(ClipboardData(text: val));
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Scanned text copied to clipboard')),
      );
    }
  }

  Widget _pageHeader() {
    return const Padding(
      padding: EdgeInsets.fromLTRB(16, 16, 16, 6),
      child: Row(
        children: [
          Icon(Icons.live_tv_outlined),
          SizedBox(width: 10),
          Expanded(
            child: Text('Workshop', style: TextStyle(fontSize: 20, fontWeight: FontWeight.w800)),
          ),
        ],
      ),
    );
  }

  Widget _infoBanner() {
    return Padding(
      padding: const EdgeInsets.fromLTRB(16, 8, 16, 0),
      child: Card(
        elevation: 0,
        color: const Color(0xFFF7F8FA),
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.circular(12),
          side: const BorderSide(color: Colors.black12),
        ),
        child: const Padding(
          padding: EdgeInsets.all(12),
          child: Text(
            'Workshops are hosted live via Zoom/Meet.\n'
            'Tap a session to view details and join. Scan QR to open host forms/links.',
            style: TextStyle(fontSize: 13),
          ),
        ),
      ),
    );
  }

  Widget _browseButton() {
    return Padding(
      padding: const EdgeInsets.fromLTRB(16, 12, 16, 8),
      child: SizedBox(
        width: double.infinity,
        child: FilledButton.icon(
          icon: const Icon(Icons.explore_outlined),
          label: const Text('Browse workshops'),
          onPressed: () {
            showModalBottomSheet(
              context: context,
              isScrollControlled: true,
              useSafeArea: true,
              backgroundColor: Colors.white,
              builder: (_) => _WorkshopsBrowserSheet(
                initialSkill: _kSkillLabels.keys.contains(_skill) ? _skill : 'all',
              ),
            );
          },
        ),
      ),
    );
  }

  Widget _sectionHeader(String title, {String? subtitle, Widget? trailing}) {
    return Padding(
      padding: const EdgeInsets.fromLTRB(16, 6, 16, 0),
      child: Row(
        children: [
          Expanded(
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Text(title, style: const TextStyle(fontSize: 18, fontWeight: FontWeight.w800)),
              if (subtitle != null && subtitle.isNotEmpty)
                Padding(
                  padding: const EdgeInsets.only(top: 2),
                  child: Text(subtitle, style: const TextStyle(fontSize: 12, color: Colors.black54)),
                ),
            ]),
          ),
          if (trailing != null) trailing,
        ],
      ),
    );
  }

  Widget _sessionCard(Map<String, dynamic> w) {
    final id = (w['id'] ?? '').toString();
    final title = (w['title'] ?? 'Session').toString();
    final when = _fmtStart(w['starts_at']);

    return InkWell(
      onTap: () {
        if (id.isEmpty) return;
        Navigator.pushNamed(context, '/workshop_detail', arguments: {'id': id});
      },
      child: SizedBox(
        width: 240,
        height: 120,
        child: Card(
          elevation: 0,
          shape: RoundedRectangleBorder(
            borderRadius: BorderRadius.circular(16),
            side: const BorderSide(color: Colors.black12),
          ),
          child: Padding(
            padding: const EdgeInsets.all(12),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                if (when.isNotEmpty)
                  Row(
                    children: [
                      const Icon(Icons.schedule, size: 16),
                      const SizedBox(width: 6),
                      Text(when, style: const TextStyle(fontSize: 12)),
                    ],
                  ),
                const Spacer(),
                Text(
                  title,
                  maxLines: 2,
                  overflow: TextOverflow.ellipsis,
                  style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 16, height: 1.2),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }

  Widget _artistCard(Map<String, dynamic> u) {
    return _SpotlightArtistCard(
      data: u,
      onOpenProfile: _openProfile,
    );
  }

  Widget _horizontalList({required List<Widget> children, required double height}) {
    if (children.isEmpty) {
      return Padding(
        padding: const EdgeInsets.fromLTRB(16, 10, 16, 10),
        child: Text('Nothing to show', style: TextStyle(color: Colors.grey.shade600)),
      );
    }
    return SizedBox(
      height: height,
      child: ListView.separated(
        padding: const EdgeInsets.fromLTRB(16, 0, 16, 0),
        scrollDirection: Axis.horizontal,
        itemCount: children.length,
        separatorBuilder: (_, __) => const SizedBox(width: 12),
        itemBuilder: (_, i) => children[i],
      ),
    );
  }

  Widget _spotlightSection() {
    return Container(
      margin: const EdgeInsets.only(top: 12),
      padding: const EdgeInsets.only(top: 10, bottom: 16),
      decoration: const BoxDecoration(
        color: Color(0xFFFAFAFB),
        border: Border(top: BorderSide(color: Color(0x11000000))),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          _sectionHeader('Spotlight artists', subtitle: 'Applaud your favorites'),
          const SizedBox(height: 8),
          _skillChipsInline(onSelected: _changeSkill, current: _skill),
          const SizedBox(height: 8),
          _horizontalList(height: 190, children: _artists.map(_artistCard).toList()),
        ],
      ),
    );
  }

  Widget _skillChipsInline({required void Function(String) onSelected, required String current}) {
    return SizedBox(
      height: 40,
      child: ListView.separated(
        padding: const EdgeInsets.symmetric(horizontal: 16),
        scrollDirection: Axis.horizontal,
        itemCount: _kSkillLabels.length,
        separatorBuilder: (_, __) => const SizedBox(width: 8),
        itemBuilder: (_, i) {
          final key = _kSkillLabels.keys.elementAt(i);
          final label = _kSkillLabels[key]!;
          final selected = current == key;
          return ChoiceChip(
            label: Text(label),
            selected: selected,
            onSelected: (_) => onSelected(key),
            shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(999)),
          );
        },
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    if (_loading) return const Center(child: CircularProgressIndicator());
    if (_err != null) {
      return Center(
        child: Column(mainAxisSize: MainAxisSize.min, children: [
          Text(_err!, style: const TextStyle(color: Colors.red)),
          const SizedBox(height: 8),
          OutlinedButton(onPressed: _load, child: const Text('Retry')),
        ]),
      );
    }

    final list = RefreshIndicator(
      onRefresh: _load,
      child: ListView(
        children: [
          _pageHeader(),
          _infoBanner(),
          _browseButton(),
          _sectionHeader(
            'Ongoing (peek)',
            subtitle: _ongoing.isEmpty ? 'No current sessions' : '${_ongoing.length} sessions',
            trailing: IconButton(icon: const Icon(Icons.refresh), onPressed: _load, tooltip: 'Refresh'),
          ),
          const SizedBox(height: 8),
          _horizontalList(height: 120, children: _ongoing.map(_sessionCard).toList()),
          _spotlightSection(),
          const SizedBox(height: 24),
        ],
      ),
    );

    return Stack(
      children: [
        list,
        Positioned(
          right: 16,
          bottom: 24,
          child: FloatingActionButton.extended(
            onPressed: _scanQr,
            label: const Text('Scan QR'),
            icon: const Icon(Icons.qr_code_scanner),
          ),
        ),
      ],
    );
  }
}

/// Full-screen workshops browser (modal bottom sheet) — simple cards, no filters.
class _WorkshopsBrowserSheet extends StatefulWidget {
  const _WorkshopsBrowserSheet({required this.initialSkill});
  final String initialSkill;

  @override
  State<_WorkshopsBrowserSheet> createState() => _WorkshopsBrowserSheetState();
}

class _WorkshopsBrowserSheetState extends State<_WorkshopsBrowserSheet>
    with SingleTickerProviderStateMixin {
  final List<Map<String, dynamic>> _items = [];
  bool _loading = true;
  String? _err;
  int _page = 1;
  bool _hasMore = true;

  final ScrollController _scroll = ScrollController();

  @override
  void initState() {
    super.initState();
    _load(reset: true);
    _scroll.addListener(() {
      if (_scroll.position.pixels >= _scroll.position.maxScrollExtent - 240) {
        _load();
      }
    });
  }

  @override
  void dispose() {
    _scroll.dispose();
    super.dispose();
  }

  Future<void> _load({bool reset = false}) async {
    if (reset) {
      setState(() {
        _loading = true;
        _err = null;
        _page = 1;
        _hasMore = true;
        _items.clear();
      });
    } else {
      if (!_hasMore || _loading) return;
      setState(() => _loading = true);
    }

    try {
      final res = await ApiService.get('workshops_list.php?status=all&page=$_page&limit=20');
      if (!mounted) return;

      final ok = (res['ok'] == true || res['items'] is List) && res['items'] != null;
      if (ok) {
        final list = (res['items'] as List)
            .cast<Map>()
            .map((e) => e.cast<String, dynamic>())
            .toList();
        setState(() {
          _items.addAll(list);
          final total = (res['total'] as int?) ?? _items.length;
          _hasMore = _items.length < total;
          _page += 1;
          _loading = false;
        });
      } else {
        setState(() {
          _loading = false;
          _err = 'Couldn’t load workshops.';
        });
      }
    } catch (e) {
      if (!mounted) return;
      setState(() {
        _loading = false;
        _err = _friendlyError(e, fallback: 'Couldn’t load workshops.');
      });
    }
  }

  Future<void> _refresh() => _load(reset: true);

  Widget _card(Map<String, dynamic> w) {
    final id = (w['id'] ?? '').toString();
    final title = (w['title'] ?? 'Workshop').toString();
    final starts = (w['starts_at'] ?? '').toString();

    String when() {
      final dt = DateTime.tryParse(starts)?.toLocal();
      if (dt == null) return '';
      String two(int n) => n < 10 ? '0$n' : '$n';
      final h = dt.hour % 12 == 0 ? 12 : dt.hour % 12;
      final ampm = dt.hour >= 12 ? 'PM' : 'AM';
      return '${dt.month}/${dt.day}/${dt.year} • $h:${two(dt.minute)} $ampm';
    }

    return Card(
      elevation: 0,
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(14),
        side: const BorderSide(color: Colors.black12),
      ),
      child: ListTile(
        title: Text(
          title,
          maxLines: 2,
          overflow: TextOverflow.ellipsis,
          style: const TextStyle(fontWeight: FontWeight.w800),
        ),
        subtitle: starts.isNotEmpty ? Text(when()) : null,
        trailing: const Icon(Icons.chevron_right),
        onTap: () {
          if (id.isEmpty) return;
          Navigator.pop(context);
          Navigator.pushNamed(context, '/workshop_detail', arguments: {'id': id});
        },
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final maxH = MediaQuery.of(context).size.height * 0.9;

    Widget listBody;
    if (_loading && _items.isEmpty) {
      listBody = ListView(
        physics: const AlwaysScrollableScrollPhysics(),
        padding: const EdgeInsets.fromLTRB(16, 24, 16, 24),
        children: const [
          SizedBox(height: 120),
          Center(child: CircularProgressIndicator()),
          SizedBox(height: 120),
        ],
      );
    } else if (_items.isEmpty) {
      listBody = ListView(
        physics: const AlwaysScrollableScrollPhysics(),
        padding: const EdgeInsets.fromLTRB(16, 24, 16, 24),
        children: const [
          SizedBox(height: 24),
          Center(child: Text('No workshops yet')),
          SizedBox(height: 24),
        ],
      );
    } else {
      listBody = ListView.separated(
        controller: _scroll,
        padding: const EdgeInsets.fromLTRB(16, 12, 16, 24),
        itemCount: _items.length + (_hasMore ? 1 : 0),
        separatorBuilder: (_, __) => const SizedBox(height: 8),
        itemBuilder: (_, i) {
          if (i >= _items.length) {
            _load();
            return const Padding(
              padding: EdgeInsets.symmetric(vertical: 16),
              child: Center(
                child: SizedBox(width: 24, height: 24, child: CircularProgressIndicator()),
              ),
            );
          }
          return _card(_items[i]);
        },
      );
    }

    return SafeArea(
      child: SizedBox(
        height: maxH,
        child: Column(
          children: [
            Padding(
              padding: const EdgeInsets.fromLTRB(16, 12, 8, 8),
              child: Row(
                children: [
                  const Text('Browse Workshops', style: TextStyle(fontSize: 18, fontWeight: FontWeight.w800)),
                  const Spacer(),
                  IconButton(onPressed: () => Navigator.pop(context), icon: const Icon(Icons.close)),
                ],
              ),
            ),
            const Divider(height: 1),
            Expanded(
              child: RefreshIndicator(
                onRefresh: _refresh,
                child: listBody,
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class _SpotlightArtistCard extends StatefulWidget {
  final Map<String, dynamic> data;
  final Future<void> Function(String userId)? onOpenProfile;
  const _SpotlightArtistCard({required this.data, this.onOpenProfile});

  @override
  State<_SpotlightArtistCard> createState() => _SpotlightArtistCardState();
}

class _SpotlightArtistCardState extends State<_SpotlightArtistCard> {
  late String id;
  late String name;
  late String handle;
  late String avatar;
  late int score;
  late bool voted;

  @override
  void initState() {
    super.initState();
    final u = widget.data;
    id = (u['id'] ?? '').toString();
    name = (u['display_name'] ?? u['name'] ?? '').toString();
    handle = (u['handle'] ?? (u['username'] != null ? '@${u['username']}' : '')).toString();
    avatar = (u['avatar_url'] ?? '').toString();
    score = (u['score'] ?? 0) as int;
    voted = (u['voted'] == true);
  }

  Future<void> _toggleVote() async {
    if (id.isEmpty) return;
    final prevV = voted;
    final prevS = score;
    setState(() {
      voted = !prevV;
      score = prevV ? (prevS - 1).clamp(0, 1 << 30) : prevS + 1;
    });

    try {
      final res = await ApiService.postForm('spotlight_vote.php', {
        'target_id': id,
        'days': '30',
      });

      if (!mounted) return;
      if (res['ok'] == true) {
        setState(() {
          voted = (res['voted'] == true);
          score = (res['score'] as int?) ?? score;
        });
      } else {
        setState(() {
          voted = prevV;
          score = prevS;
        });
        ScaffoldMessenger.of(context).showSnackBar(const SnackBar(content: Text('Could not record your vote.')));
      }
    } catch (_) {
      if (!mounted) return;
      setState(() {
        voted = prevV;
        score = prevS;
      });
      ScaffoldMessenger.of(context).showSnackBar(const SnackBar(content: Text('Could not record your vote.')));
    }
  }

  @override
  Widget build(BuildContext context) {
    return InkWell(
      onTap: id.isEmpty ? null : () => widget.onOpenProfile?.call(id),
      child: SizedBox(
        width: 140,
        child: Card(
          elevation: 0,
          color: const Color(0xFFF7F7F8),
          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
          child: Padding(
            padding: const EdgeInsets.fromLTRB(12, 14, 12, 12),
            child: Column(
              children: [
                Avatar(url: avatar, size: 56),
                const SizedBox(height: 10),
                Text(
                  name,
                  textAlign: TextAlign.center,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: const TextStyle(fontWeight: FontWeight.w800),
                ),
                if (handle.isNotEmpty)
                  Text(
                    handle,
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: const TextStyle(color: Colors.black54, fontSize: 12),
                    textAlign: TextAlign.center,
                  ),
                const Spacer(),
                SizedBox(
                  height: 36,
                  child: OutlinedButton.icon(
                    onPressed: _toggleVote,
                    icon: Icon(
                      voted ? Icons.whatshot : Icons.whatshot_outlined,
                      size: 16,
                      color: voted ? Colors.redAccent : null,
                    ),
                    label: Text('$score'),
                    style: OutlinedButton.styleFrom(
                      shape: const StadiumBorder(),
                      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
                      minimumSize: const Size(0, 0),
                      foregroundColor: Colors.black,
                      side: const BorderSide(color: Colors.black, width: 1.2),
                    ),
                  ),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}

class _QuickScanSheet extends StatefulWidget {
  const _QuickScanSheet();

  @override
  State<_QuickScanSheet> createState() => _QuickScanSheetState();
}

class _QuickScanSheetState extends State<_QuickScanSheet> {
  bool _done = false;
  late final MobileScannerController _controller;

  @override
  void initState() {
    super.initState();
    _controller = MobileScannerController();
  }

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return SafeArea(
      child: Stack(
        children: [
          MobileScanner(
            controller: _controller,
            fit: BoxFit.cover,
            onDetect: (capture) {
              if (_done) return;
              for (final b in capture.barcodes) {
                final raw = b.rawValue ?? '';
                if (raw.isNotEmpty) {
                  _done = true;
                  Navigator.of(context).pop({'value': raw});
                  return;
                }
              }
            },
          ),
          Positioned(
            top: 8,
            right: 8,
            left: 8,
            child: Row(
              children: [
                IconButton(color: Colors.white, icon: const Icon(Icons.close), onPressed: () => Navigator.pop(context)),
                const Spacer(),
                ValueListenableBuilder<TorchState>(
                  valueListenable: _controller.torchState,
                  builder: (_, state, __) {
                    final on = state == TorchState.on;
                    return IconButton(
                      color: Colors.white,
                      icon: Icon(on ? Icons.flash_on : Icons.flash_off),
                      onPressed: () => _controller.toggleTorch(),
                    );
                  },
                ),
              ],
            ),
          ),
          Align(
            alignment: Alignment.bottomCenter,
            child: Padding(
              padding: const EdgeInsets.all(12),
              child: Container(
                padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
                decoration: BoxDecoration(
                  color: const Color(0xAA000000),
                  borderRadius: BorderRadius.circular(999),
                ),
                child: const Text('Point camera at the QR', style: TextStyle(color: Colors.white)),
              ),
            ),
          ),
        ],
      ),
    );
  }
}
