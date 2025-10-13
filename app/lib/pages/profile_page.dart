import 'dart:io';
import 'package:flutter/material.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:video_player/video_player.dart';
import '../services/api.dart';
import 'follow_list_page.dart';

class ProfilePage extends StatefulWidget {
  const ProfilePage({super.key});
  @override
  State<ProfilePage> createState() => _ProfilePageState();
}

class _ProfilePageState extends State<ProfilePage> {
  Map<String, dynamic> _data = {};
  bool _loading = true;
  String? _err;

  String _friendly(Object error, {String? fallback}) {
    final fb = fallback ?? 'Something went wrong. Please try again.';
    if (error is SocketException) return 'No internet connection.';
    if (error is HttpException) return 'Unable to reach the server.';
    final msg = error.toString().toLowerCase();
    if (msg.contains('timeout')) return 'Request timed out. Please retry.';
    if (msg.contains('unauthorized') || msg.contains('401')) return 'Please sign in again.';
    if (msg.contains('forbidden') || msg.contains('403')) return 'You don’t have permission to do that.';
    if (msg.contains('server') || msg.contains('500') || msg.contains('502') || msg.contains('503') || msg.contains('504')) {
      return 'Server issue. Please try again later.';
    }
    return fb;
  }

  @override
  void initState() {
    super.initState();
    _refresh();
  }

  Future<void> _refresh() async {
    setState(() {
      _loading = true;
      _err = null;
    });

    try {
      final res = await ApiService.get('profile_overview.php');
      if (!mounted) return;

      if (res['user'] != null) {
        _data = res;

        try {
          final counts = res['counts'] as Map<String, dynamic>?;
          if (counts == null || !counts.containsKey('followers')) {
            final c = await ApiService.get('follow_counts.php');
            if (c['counts'] is Map) _data['counts'] = c['counts'];
          }
        } catch (_) {}

        setState(() => _loading = false);
      } else {
        setState(() {
          _loading = false;
          _err = _friendly(Exception(res['error'] ?? 'load_failed'), fallback: 'Couldn’t load profile.');
        });
      }
    } catch (e) {
      if (!mounted) return;
      setState(() {
        _loading = false;
        _err = _friendly(e, fallback: 'Couldn’t load profile.');
      });
    }
  }

  Future<void> _logout() async {
    final sp = await SharedPreferences.getInstance();
    await sp.remove('token');
    await ApiService.clearToken();
    if (!mounted) return;
    Navigator.of(context).pushNamedAndRemoveUntil('/login', (_) => false);
  }

  Future<void> _applyForMentor() async {
    setState(() => _loading = true);
    try {
      final res = await ApiService.post('mentor_application.php', {});
      if (!mounted) return;

      if (res['ok'] == true) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(content: Text('Your mentor application has been submitted.')),
        );
      } else {
        final code = (res['error'] ?? '').toString();
        final friendly = switch (code) {
          'ALREADY_VERIFIED' => 'You are already a verified mentor.',
          'ALREADY_PENDING' => 'Your mentor application is already pending.',
          _ => 'Request couldn’t be completed. Please try again.'
        };
        ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(friendly)));
      }
    } catch (e) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(_friendly(e))));
    } finally {
      if (mounted) await _refresh();
    }
  }

  List<String> _extractSkillLabels(Map<String, dynamic> user) {
    final rawTop = _data['skills'];
    final rawUser = user['skills'];
    final otherTop = (_data['skills_other'] ?? '').toString().trim();

    List labels = [];
    if (rawTop is List) {
      labels = rawTop;
    } else if (rawUser is List) {
      labels = rawUser;
    }

    final out = <String>[];
    for (final s in labels) {
      if (s is Map) {
        final label = (s['label'] ?? s['other'] ?? s['other_label'] ?? s['key'] ?? s['code'] ?? s['skill'] ?? '').toString();
        out.add(label.isNotEmpty ? label : 'Other');
      } else if (s is String) {
        final key = s.toLowerCase();
        if (key == 'other') {
          out.add(otherTop.isNotEmpty ? otherTop : 'Other');
        } else {
          out.add(key.isEmpty ? 'Other' : key[0].toUpperCase() + key.substring(1));
        }
      }
    }
    return out;
  }

  Widget _pill(String text, {Color? color}) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 5),
      decoration: BoxDecoration(
        color: color ?? Colors.black12,
        borderRadius: BorderRadius.circular(999),
      ),
      child: Text(text, style: const TextStyle(fontSize: 12, fontWeight: FontWeight.w600)),
    );
  }

  Widget _skillRow(List<String> skills) {
    if (skills.isEmpty) return const SizedBox.shrink();
    const maxShown = 4;
    final shown = skills.take(maxShown).toList();
    final extra = skills.length - shown.length;
    return Wrap(
      alignment: WrapAlignment.start,
      spacing: 6,
      runSpacing: 6,
      children: [
        ...shown.map(_pill),
        if (extra > 0) _pill('+$extra'),
      ],
    );
  }

  bool _looksLikeVideo(String url, [String? type]) {
    final t = (type ?? '').toLowerCase();
    if (t == 'video' || t.startsWith('video/')) return true;
    String path = '';
    try { path = Uri.parse(url).path.toLowerCase(); } catch (_) { path = url.toLowerCase(); }
    return path.endsWith('.mp4') || path.endsWith('.m4v') || path.endsWith('.mov') || path.endsWith('.webm');
  }

  @override
  Widget build(BuildContext context) {
    if (_loading) return const Center(child: CircularProgressIndicator());
    if (_err != null) {
      return Center(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            const Icon(Icons.info_outline),
            const SizedBox(height: 8),
            Text(_err!, textAlign: TextAlign.center),
            const SizedBox(height: 8),
            OutlinedButton(onPressed: _refresh, child: const Text('Retry')),
          ],
        ),
      );
    }

    final u = (_data['user'] as Map<String, dynamic>?) ?? {};
    final id = (u['id'] ?? '').toString();
    final name = (u['display_name'] ?? u['name'] ?? '').toString();
    final username = (u['username'] ?? '').toString();
    final bio = (u['bio'] ?? '').toString().trim();
    final avatar = (u['avatar_url'] ?? '').toString();
    final skills = _extractSkillLabels(u);

    final role = (u['role'] ?? '').toString().toLowerCase();
    final status = (u['status'] ?? '').toString().toLowerCase();

    final isAdmin = role == 'admin';
    final isMentor = role == 'mentor';
    final isPending = status == 'pending';
    final isVerified = status == 'verified';

    final showApplyBtn = !isMentor && !isPending && !isVerified;
    final hasInconsistentVerified = isVerified && !(isMentor || isAdmin);

    final counts = (_data['counts'] as Map<String, dynamic>?) ?? const {};
    final posts = (counts['posts'] ?? 0) as int;
    final followers = (counts['followers'] ?? 0) as int;
    final following = (counts['following'] ?? 0) as int;

    return SafeArea(
      bottom: false,
      child: RefreshIndicator(
        onRefresh: _refresh,
        child: ListView(
          padding: const EdgeInsets.fromLTRB(16, 32, 16, 16),
          children: [
            Row(
              crossAxisAlignment: CrossAxisAlignment.center,
              children: [
                Container(
                  padding: const EdgeInsets.all(3),
                  decoration: BoxDecoration(
                    shape: BoxShape.circle,
                    border: Border.all(color: Colors.black, width: 1.2),
                  ),
                  child: CircleAvatar(
                    radius: 34,
                    backgroundImage: avatar.isNotEmpty ? NetworkImage(avatar) : null,
                    child: avatar.isEmpty ? const Icon(Icons.person) : null,
                  ),
                ),
                const SizedBox(width: 14),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Row(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Expanded(
                            child: Column(
                              crossAxisAlignment: CrossAxisAlignment.start,
                              children: [
                                Text(name, style: const TextStyle(fontSize: 22, fontWeight: FontWeight.w800)),
                                if (username.isNotEmpty) ...[
                                  const SizedBox(height: 2),
                                  Text('@$username', style: const TextStyle(color: Colors.black54)),
                                ],
                                const SizedBox(height: 8),
                                if (isVerified && (isMentor || isAdmin))
                                  _pill('Verified mentor', color: const Color(0xFFDFF6DD))
                                else if (isMentor)
                                  _pill('Mentor', color: const Color(0xFFDFF6DD))
                                else if (isPending)
                                  _pill('Application pending', color: const Color(0xFFFFF4CC)),
                                if (hasInconsistentVerified) ...[
                                  const SizedBox(height: 6),
                                  Text(
                                    'Account is verified but not set as mentor.',
                                    style: TextStyle(color: Colors.orange.shade700, fontSize: 11),
                                  ),
                                ],
                              ],
                            ),
                          ),
                          PopupMenuButton<String>(
                            onSelected: (v) async {
                              if (v == 'edit') {
                                final changed = await Navigator.pushNamed(context, '/profile_edit');
                                if (changed == true && mounted) _refresh();
                              } else if (v == 'logout') {
                                await _logout();
                              } else if (v == 'admin') {
                                Navigator.pushNamed(context, '/admin');
                              } else if (v == 'reports') {
                                Navigator.pushNamed(context, '/admin_reports');
                              }
                            },
                            itemBuilder: (c) => [
                              const PopupMenuItem(value: 'edit', child: Text('Edit profile')),
                              if (isAdmin) const PopupMenuItem(value: 'admin', child: Text('Admin panel')),
                              if (isAdmin) const PopupMenuItem(value: 'reports', child: Text('Admin reports')),
                              const PopupMenuItem(value: 'logout', child: Text('Logout')),
                            ],
                            icon: const Icon(Icons.more_horiz),
                          ),
                        ],
                      ),
                    ],
                  ),
                ),
              ],
            ),
            if (bio.isNotEmpty) ...[
              const SizedBox(height: 12),
              Text(bio, style: const TextStyle(height: 1.25)),
            ],
            if (skills.isNotEmpty) ...[
              const SizedBox(height: 10),
              _skillRow(skills),
            ],
            const SizedBox(height: 16),
            if (showApplyBtn)
              ElevatedButton(
                onPressed: _applyForMentor,
                child: const Text('Apply to become a Mentor'),
              )
            else if (isPending)
              const OutlinedButton(
                onPressed: null,
                child: Text('Mentor application pending review'),
              ),
            const SizedBox(height: 16),
            Row(
              children: [
                _Stat(label: 'Posts', value: posts.toString(), onTap: null),
                _Stat(
                  label: 'Followers',
                  value: followers.toString(),
                  onTap: () async {
                    await Navigator.push(
                      context,
                      MaterialPageRoute(
                        builder: (_) => FollowListPage(userId: id, initialType: 'followers'),
                      ),
                    );
                    if (mounted) _refresh();
                  },
                ),
                _Stat(
                  label: 'Following',
                  value: following.toString(),
                  onTap: () async {
                    await Navigator.push(
                      context,
                      MaterialPageRoute(
                        builder: (_) => FollowListPage(userId: id, initialType: 'following'),
                      ),
                    );
                    if (mounted) _refresh();
                  },
                ),
              ],
            ),
            const SizedBox(height: 12),
            const Divider(height: 1),
            const SizedBox(height: 12),
            _grid(_data['posts'] as List<dynamic>?),
          ],
        ),
      ),
    );
  }

  Future<void> _openPost(String postId) async {
    if (postId.isEmpty) return;
    await showModalBottomSheet(
      context: context,
      useRootNavigator: true,
      isScrollControlled: true,
      backgroundColor: Colors.white,
      builder: (_) => _PostPreviewSheet(postId: postId),
    );
  }

  Widget _grid(List<dynamic>? items) {
    final list = (items ?? const []).cast<Map<String, dynamic>>();
    if (list.isEmpty) {
      return Container(
        alignment: Alignment.centerLeft,
        height: 200,
        child: const Text('No posts yet'),
      );
    }
    return GridView.builder(
      shrinkWrap: true,
      physics: const NeverScrollableScrollPhysics(),
      itemCount: list.length,
      gridDelegate: const SliverGridDelegateWithFixedCrossAxisCount(
        crossAxisCount: 2, crossAxisSpacing: 10, mainAxisSpacing: 10,
      ),
      itemBuilder: (_, i) {
        final p = list[i];
        final url = (p['media_url'] ?? '').toString();
        final type = (p['media_type'] ?? '').toString();
        final id = (p['id'] ?? p['post_id'] ?? '').toString();

        final isVideo = _looksLikeVideo(url, type);

        final child = url.isEmpty
            ? Container(color: Colors.black12)
            : (isVideo
                ? _InlineVideo(url: url, muted: true, showControls: false, interceptGestures: false)
                : Image.network(url, fit: BoxFit.cover));

        final tile = ClipRRect(
          borderRadius: BorderRadius.circular(12),
          child: child,
        );

        if (id.isEmpty) return tile;

        return GestureDetector(
          behavior: HitTestBehavior.opaque,
          onTap: () => _openPost(id),
          child: Semantics(button: true, child: tile),
        );
      },
    );
  }
}

class _Stat extends StatelessWidget {
  final String label;
  final String value;
  final VoidCallback? onTap;
  const _Stat({required this.label, required this.value, this.onTap});

  @override
  Widget build(BuildContext context) {
    final child = Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(value, style: const TextStyle(fontSize: 18, fontWeight: FontWeight.w800)),
        const SizedBox(height: 2),
        Text(label, style: const TextStyle(color: Colors.grey)),
      ],
    );
    return Expanded(
      child: InkWell(
        onTap: onTap,
        borderRadius: BorderRadius.circular(12),
        child: Padding(
          padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 10),
          child: child,
        ),
      ),
    );
  }
}

// ---------- Post preview (loads post_get.php) ----------
class _PostPreviewSheet extends StatefulWidget {
  final String postId;
  const _PostPreviewSheet({required this.postId});
  @override
  State<_PostPreviewSheet> createState() => _PostPreviewSheetState();
}

class _PostPreviewSheetState extends State<_PostPreviewSheet> {
  Map<String, dynamic>? _post;
  bool _loading = true;
  String? _err;

  String _friendly(Object error, {String? fallback}) {
    final fb = fallback ?? 'Something went wrong. Please try again.';
    if (error is SocketException) return 'No internet connection.';
    if (error is HttpException) return 'Unable to reach the server.';
    final msg = error.toString().toLowerCase();
    if (msg.contains('timeout')) return 'Request timed out. Please retry.';
    if (msg.contains('404') || msg.contains('not_found')) return 'Post not available.';
    if (msg.contains('server') || msg.contains('500') || msg.contains('502') || msg.contains('503') || msg.contains('504')) {
      return 'Server issue. Please try again later.';
    }
    return fb;
  }

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

    try {
      final r = await ApiService.get('post_get.php?id=${Uri.encodeQueryComponent(widget.postId)}');
      if (!mounted) return;

      if ((r['ok'] == true) && r['post'] is Map) {
        setState(() {
          _post = (r['post'] as Map).cast<String, dynamic>();
          _loading = false;
        });
      } else {
        setState(() {
          _loading = false;
          _err = _friendly(Exception(r['error'] ?? 'not_found'), fallback: 'Post not available.');
        });
      }
    } catch (e) {
      if (!mounted) return;
      setState(() {
        _loading = false;
        _err = _friendly(e, fallback: 'Couldn’t load post.');
      });
    }
  }

  bool _isVideo(String url, String type) {
    final t = type.toLowerCase();
    if (t == 'video' || t.startsWith('video/')) return true;
    String path = '';
    try { path = Uri.parse(url).path.toLowerCase(); } catch (_) { path = url.toLowerCase(); }
    return path.endsWith('.mp4') || path.endsWith('.m4v') || path.endsWith('.mov') || path.endsWith('.webm');
  }

  @override
  Widget build(BuildContext context) {
    if (_loading) {
      return const SizedBox(
        height: 420,
        child: Center(child: CircularProgressIndicator()),
      );
    }
    if (_err != null || _post == null) {
      return SizedBox(
        height: 200,
        child: Center(
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              const Icon(Icons.info_outline),
              const SizedBox(height: 8),
              Text(_err ?? 'Post not available.', textAlign: TextAlign.center),
              const SizedBox(height: 8),
              OutlinedButton(onPressed: _load, child: const Text('Retry')),
            ],
          ),
        ),
      );
    }

    final p = _post!;
    final url = (p['media_url'] ?? '').toString();
    final type = (p['media_type'] ?? '').toString();
    final caption = (p['caption'] ?? '').toString();
    final likes = (p['likes'] ?? 0) as int? ?? 0;
    final comments = (p['comments'] ?? 0) as int? ?? 0;
    final liked = p['liked'] == true;

    final u = (p['user'] as Map?)?.cast<String, dynamic>() ?? {};
    final name = (u['display_name'] ?? '').toString();
    final username = (u['username'] ?? '').toString();
    final avatar = (u['avatar_url'] ?? '').toString();

    // Force a visible height for the media box (square by default).
    final screenW = MediaQuery.of(context).size.width;
    final mediaHeight = screenW; // 1:1; change to screenW * 9/16 for 16:9

    return DraggableScrollableSheet(
      initialChildSize: 0.9,
      minChildSize: 0.6,
      maxChildSize: 0.95,
      expand: false,
      builder: (_, scroll) => Padding(
        padding: const EdgeInsets.fromLTRB(16, 8, 16, 8),
        child: ListView(
          controller: scroll,
          children: [
            Container(
              width: 40,
              height: 4,
              decoration: BoxDecoration(color: Colors.black12, borderRadius: BorderRadius.circular(2)),
            ),
            const SizedBox(height: 12),
            Row(
              children: [
                CircleAvatar(backgroundImage: avatar.isNotEmpty ? NetworkImage(avatar) : null),
                const SizedBox(width: 8),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(name, style: const TextStyle(fontWeight: FontWeight.w700)),
                      if (username.isNotEmpty) const SizedBox(height: 2),
                      if (username.isNotEmpty) Text('@$username', style: const TextStyle(color: Colors.black54)),
                    ],
                  ),
                ),
              ],
            ),
            const SizedBox(height: 10),

            // >>> MEDIA AREA WITH GUARANTEED HEIGHT <<<
            ClipRRect(
              borderRadius: BorderRadius.circular(12),
              child: SizedBox(
                width: double.infinity,
                height: mediaHeight,
                child: _isVideo(url, type)
                    ? _InlineVideo(
                        url: url,
                        muted: false,
                        showControls: true,
                        interceptGestures: true,
                      )
                    : (url.isNotEmpty
                        ? Image.network(url, fit: BoxFit.cover)
                        : Container(color: Colors.black12)),
              ),
            ),

            if (caption.isNotEmpty) ...[
              const SizedBox(height: 8),
              Text(caption),
            ],
            const SizedBox(height: 8),
            const Divider(height: 1),
            const SizedBox(height: 2),
            Row(
              children: [
                IconButton(
                  onPressed: () async {
                    // optimistic like toggle
                    final prevLiked = liked;
                    final prevLikes = likes;
                    p['liked'] = !prevLiked;
                    p['likes'] = prevLiked ? (prevLikes - 1).clamp(0, 1 << 30) : prevLikes + 1;
                    setState(() {});
                    final res = await ApiService.postForm('like_toggle.php', {'post_id': widget.postId});
                    if (res['ok'] != true) {
                      p['liked'] = prevLiked;
                      p['likes'] = prevLikes;
                      if (mounted) setState(() {});
                      if (mounted) {
                        ScaffoldMessenger.of(context).showSnackBar(
                          const SnackBar(content: Text('Couldn’t update like. Please try again.')),
                        );
                      }
                    }
                  },
                  icon: Icon(liked ? Icons.favorite : Icons.favorite_border),
                ),
                Text('${p['likes'] ?? 0}'),
                const SizedBox(width: 12),
                IconButton(
                  onPressed: () async {
                    await showModalBottomSheet(
                      context: context,
                      isScrollControlled: true,
                      builder: (_) => _PostCommentsQuick(postId: widget.postId),
                    );
                  },
                  icon: const Icon(Icons.mode_comment_outlined),
                ),
                Text('$comments'),
              ],
            ),
          ],
        ),
      ),
    );
  }
}

// ---------- Minimal comments viewer ----------
class _PostCommentsQuick extends StatelessWidget {
  final String postId;
  const _PostCommentsQuick({required this.postId});

  @override
  Widget build(BuildContext context) {
    return DraggableScrollableSheet(
      initialChildSize: 0.8,
      maxChildSize: 0.95,
      minChildSize: 0.5,
      expand: false,
      builder: (ctx, scroll) {
        return FutureBuilder<Map<String, dynamic>>(
          future: ApiService.get('comments_list.php?post_id=${Uri.encodeQueryComponent(postId)}&page=1&limit=20'),
          builder: (ctx, snap) {
            if (!snap.hasData) {
              return const Center(child: CircularProgressIndicator());
            }
            final res = snap.data!;
            final ok = res['ok'] == true;
            final items = (res['items'] as List?)
                    ?.cast<Map>()
                    .map((e) => e.cast<String, dynamic>())
                    .toList() ??
                const <Map<String, dynamic>>[];

            if (!ok) {
              return Padding(
                padding: const EdgeInsets.all(16),
                child: Column(
                  mainAxisSize: MainAxisSize.min,
                  children: const [
                    Icon(Icons.info_outline),
                    SizedBox(height: 8),
                    Text('Couldn’t load comments. Please try again.'),
                  ],
                ),
              );
            }

            return Padding(
              padding: const EdgeInsets.fromLTRB(16, 8, 16, 8),
              child: Column(
                children: [
                  Container(
                    width: 40,
                    height: 4,
                    decoration: BoxDecoration(color: Colors.black12, borderRadius: BorderRadius.circular(2)),
                  ),
                  const SizedBox(height: 12),
                  Expanded(
                    child: ListView.separated(
                      controller: scroll,
                      itemCount: items.length,
                      separatorBuilder: (_, __) => const SizedBox(height: 10),
                      itemBuilder: (_, i) {
                        final c = items[i];
                        final u = (c['user'] as Map?)?.cast<String, dynamic>() ?? {};
                        final name = (u['display_name'] ?? '').toString();
                        final username = (u['username'] ?? '').toString();
                        final avatar = (u['avatar_url'] ?? '').toString();
                        final text = (c['body'] ?? '').toString();
                        final time = (c['time_ago'] ?? c['time'] ?? '').toString();
                        return Row(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            CircleAvatar(radius: 14, backgroundImage: avatar.isNotEmpty ? NetworkImage(avatar) : null),
                            const SizedBox(width: 8),
                            Expanded(
                              child: Column(
                                crossAxisAlignment: CrossAxisAlignment.start,
                                children: [
                                  Row(
                                    children: [
                                      Text(name, style: const TextStyle(fontWeight: FontWeight.w700)),
                                      if (username.isNotEmpty) ...[
                                        const SizedBox(width: 6),
                                        Text('@$username', style: const TextStyle(color: Colors.black54)),
                                      ],
                                      const SizedBox(width: 8),
                                      Text(time, style: const TextStyle(color: Colors.black45, fontSize: 12)),
                                    ],
                                  ),
                                  const SizedBox(height: 4),
                                  Text(text),
                                ],
                              ),
                            ),
                          ],
                        );
                      },
                    ),
                  ),
                ],
              ),
            );
          },
        );
      },
    );
  }
}

/// ---------- Inline video used by grid & preview ----------
class _InlineVideo extends StatefulWidget {
  final String url;
  final bool muted;            // grid: true, preview: false
  final bool showControls;     // preview: true, grid: false
  final bool autoplay;
  final bool loop;
  final bool interceptGestures; // grid: false (let parent handle taps)

  const _InlineVideo({
    super.key,
    required this.url,
    this.muted = true,
    this.showControls = false,
    this.autoplay = true,
    this.loop = true,
    this.interceptGestures = true,
  });

  @override
  State<_InlineVideo> createState() => _InlineVideoState();
}

class _InlineVideoState extends State<_InlineVideo> {
  VideoPlayerController? _c;
  bool _init = false;
  bool _err = false;
  bool _muted = true;
  bool _playing = false;

  @override
  void initState() {
    super.initState();
    _muted = widget.muted;
    _c = VideoPlayerController.networkUrl(Uri.parse(widget.url))
      ..setLooping(widget.loop)
      ..initialize().then((_) async {
        if (!mounted) return;
        if (widget.autoplay) {
          await _c!.play();
          _playing = true;
        }
        await _c!.setVolume(_muted ? 0 : 1);
        if (mounted) setState(() => _init = true);
      }).catchError((_) {
        if (mounted) setState(() => _err = true);
      });
  }

  @override
  void dispose() {
    _c?.dispose();
    super.dispose();
  }

  Future<void> _togglePlay() async {
    if (!(_c?.value.isInitialized ?? false)) return;
    if (_c!.value.isPlaying) {
      await _c!.pause();
      setState(() => _playing = false);
    } else {
      await _c!.play();
      setState(() => _playing = true);
    }
  }

  Future<void> _toggleMute() async {
    if (!(_c?.value.isInitialized ?? false)) return;
    _muted = !_muted;
    await _c!.setVolume(_muted ? 0 : 1);
    setState(() {});
  }

  @override
  Widget build(BuildContext context) {
    // Always report a size so parents in a ListView get real height.
    final ar = (_c?.value.aspectRatio ?? 0) <= 0 ? 1.0 : _c!.value.aspectRatio;

    if (_err) {
      return AspectRatio(
        aspectRatio: ar,
        child: Container(
          color: Colors.black12,
          alignment: Alignment.center,
          child: const Icon(Icons.videocam_off, color: Colors.black45),
        ),
      );
    }
    if (!_init || _c == null) {
      return const AspectRatio(
        aspectRatio: 1.0,
        child: Center(
          child: SizedBox(
            width: 22,
            height: 22,
            child: CircularProgressIndicator(strokeWidth: 2),
          ),
        ),
      );
    }

    final player = VideoPlayer(_c!);

    // GRID MODE: ignore touches so outer GestureDetector receives taps
    if (!widget.interceptGestures) {
      return AspectRatio(
        aspectRatio: ar,
        child: IgnorePointer(
          ignoring: true,
          child: Stack(
            fit: StackFit.expand,
            children: [
              FittedBox(
                fit: BoxFit.cover,
                clipBehavior: Clip.hardEdge,
                child: SizedBox(
                  width: 1000,
                  height: 1000 / ar,
                  child: player,
                ),
              ),
              if (widget.muted)
                Positioned(
                  right: 6,
                  bottom: 6,
                  child: Container(
                    padding: const EdgeInsets.all(6),
                    decoration: BoxDecoration(color: const Color(0x66000000), borderRadius: BorderRadius.circular(999)),
                    child: const Icon(Icons.volume_off, size: 16, color: Colors.white),
                  ),
                ),
            ],
          ),
        ),
      );
    }

    // PREVIEW MODE
    return AspectRatio(
      aspectRatio: ar,
      child: Stack(
        fit: StackFit.expand,
        children: [
          FittedBox(
            fit: BoxFit.cover,
            clipBehavior: Clip.hardEdge,
            child: SizedBox(
              width: 1000,
              height: 1000 / ar,
              child: player,
            ),
          ),
          if (widget.showControls)
            Positioned(
              right: 6,
              bottom: 6,
              child: Row(
                children: [
                  _RoundBtn(icon: _playing ? Icons.pause : Icons.play_arrow, onTap: _togglePlay),
                  const SizedBox(width: 6),
                  _RoundBtn(icon: _muted ? Icons.volume_off : Icons.volume_up, onTap: _toggleMute),
                ],
              ),
            ),
          // Tap anywhere to toggle play/pause in preview
          Material(
            type: MaterialType.transparency,
            child: InkWell(onTap: _togglePlay),
          ),
        ],
      ),
    );
  }
}

class _RoundBtn extends StatelessWidget {
  final IconData icon;
  final VoidCallback onTap;
  const _RoundBtn({required this.icon, required this.onTap});
  @override
  Widget build(BuildContext context) {
    return Material(
      color: const Color(0xAA000000),
      shape: const CircleBorder(),
      child: InkWell(
        customBorder: const CircleBorder(),
        onTap: onTap,
        child: Padding(
          padding: const EdgeInsets.all(8.0),
          child: Icon(icon, size: 18, color: Colors.white),
        ),
      ),
    );
  }
}
