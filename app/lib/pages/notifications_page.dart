// lib/pages/notifications_page.dart
import 'dart:io';
import 'package:flutter/material.dart';
import 'package:video_player/video_player.dart';
import '../services/api.dart';
import '../widgets/avatar.dart';

const double kBrandTop = 32.0;

class NotificationsPage extends StatefulWidget {
  const NotificationsPage({super.key});
  @override
  State<NotificationsPage> createState() => _NotificationsPageState();
}

class _NotificationsPageState extends State<NotificationsPage> {
  final List<Map<String, dynamic>> _items = [];
  bool _loading = true;
  String? _err;
  int _page = 1;
  bool _hasMore = true;
  bool _marking = false;
  int _unread = 0;

  // User-safe error mapping (why: avoid dev/internal details in UI).
  String _friendly(Object error, {String? fallback}) {
    final fb = fallback ?? 'Something went wrong. Please try again.';
    if (error is SocketException) return 'No internet connection.';
    if (error is HttpException) return 'Unable to reach the server.';
    final msg = error.toString().toLowerCase();
    if (msg.contains('timeout')) return 'Request timed out. Please retry.';
    if (msg.contains('unauthorized') || msg.contains('401')) return 'Please sign in again.';
    if (msg.contains('forbidden') || msg.contains('403')) return 'You don’t have permission to do that.';
    if (msg.contains('404')) return 'Not found.';
    if (msg.contains('server') || msg.contains('500') || msg.contains('502') || msg.contains('503') || msg.contains('504')) {
      return 'Server issue. Please try again later.';
    }
    return fb;
  }

  @override
  void initState() {
    super.initState();
    _load(reset: true);
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
      if (!_hasMore) return;
    }

    try {
      final res = await ApiService.get('notifications_list.php?page=$_page&limit=20');
      if (!mounted) return;

      if (res['ok'] == true && res['items'] is List) {
        final list = (res['items'] as List).cast<Map>().map((e) => e.cast<String, dynamic>()).toList();
        setState(() {
          _items.addAll(list);
          _unread = (res['unread'] as int?) ?? 0;
          final total = (res['total'] as int?) ?? _items.length;
          _hasMore = _items.length < total;
          _page += 1;
          _loading = false;
        });
      } else {
        setState(() {
          _loading = false;
          _err = _friendly(Exception(res['error'] ?? 'load_failed'), fallback: 'Couldn’t load notifications.');
        });
      }
    } catch (e) {
      if (!mounted) return;
      setState(() {
        _loading = false;
        _err = _friendly(e, fallback: 'Couldn’t load notifications.');
      });
    }
  }

  Future<void> _markAllRead() async {
    if (_marking) return;
    setState(() => _marking = true);
    try {
      final r = await ApiService.postForm('notifications_mark_read.php', {});
      if (!mounted) return;
      setState(() {
        _marking = false;
        if (r['ok'] == true) {
          _unread = 0;
          for (var i = 0; i < _items.length; i++) {
            _items[i] = {..._items[i], 'read': true};
          }
        } else {
          ScaffoldMessenger.of(context).showSnackBar(
            const SnackBar(content: Text('Couldn’t mark as read. Please try again.')),
          );
        }
      });
    } catch (e) {
      if (!mounted) return;
      setState(() => _marking = false);
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(_friendly(e))),
      );
    }
  }

  Future<void> _open(Map<String, dynamic> n) async {
    final type = (n['type'] ?? '').toString();
    final actor = (n['actor'] as Map?)?.cast<String, dynamic>() ?? {};
    final actorId = (actor['id'] ?? '').toString();
    final postId = n['post_id']?.toString();

    if (type == 'follow' && actorId.isNotEmpty) {
      try {
        await Navigator.of(context).pushNamed('/user', arguments: {'id': actorId});
      } catch (_) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(content: Text('Can’t open profile right now.')),
        );
      }
      return;
    }

    if (postId != null && postId.isNotEmpty) {
      if (type == 'comment') {
        await showModalBottomSheet(
          context: context,
          isScrollControlled: true,
          builder: (_) => _PostCommentsQuick(postId: postId),
        );
      } else {
        await showModalBottomSheet(
          context: context,
          isScrollControlled: true,
          backgroundColor: Colors.white,
          builder: (_) => _PostPreviewSheet(postId: postId),
        );
      }
      return;
    }

    ScaffoldMessenger.of(context).showSnackBar(
      const SnackBar(content: Text('Nothing to open right now.')),
    );
  }

  String _title(Map<String, dynamic> n) {
    final type = (n['type'] ?? '').toString();
    final actor = (n['actor'] as Map?)?.cast<String, dynamic>() ?? {};
    final name = (actor['display_name'] ?? 'Someone').toString();
    switch (type) {
      case 'like':
        return '$name liked your post';
      case 'comment':
        return '$name commented on your post';
      case 'follow':
        return '$name started following you';
      default:
        return '$name sent you a notification';
    }
  }

  @override
  Widget build(BuildContext context) {
    if (_loading && _items.isEmpty) {
      return const Scaffold(body: Center(child: CircularProgressIndicator()));
    }
    return Scaffold(
      appBar: AppBar(
        title: const Text('Notifications'),
        actions: [
          if (_unread > 0)
            TextButton(
              onPressed: _marking ? null : _markAllRead,
              child: _marking
                  ? const SizedBox(width: 16, height: 16, child: CircularProgressIndicator(strokeWidth: 2))
                  : const Text('Mark all read'),
            ),
        ],
      ),
      body: RefreshIndicator(
        onRefresh: () => _load(reset: true),
        child: _err != null
            ? ListView(
                padding: const EdgeInsets.fromLTRB(16, kBrandTop, 16, 16),
                children: [
                  const Icon(Icons.info_outline),
                  const SizedBox(height: 8),
                  Text(_err!),
                  const SizedBox(height: 8),
                  OutlinedButton(onPressed: () => _load(reset: true), child: const Text('Retry')),
                ],
              )
            : NotificationListener<ScrollNotification>(
                onNotification: (n) {
                  if (n.metrics.pixels >= n.metrics.maxScrollExtent - 200) _load();
                  return false;
                },
                child: ListView.separated(
                  padding: const EdgeInsets.fromLTRB(16, kBrandTop, 16, 16),
                  itemCount: _items.length + (_hasMore ? 1 : 0),
                  separatorBuilder: (_, __) => const SizedBox(height: 8),
                  itemBuilder: (_, i) {
                    if (i >= _items.length) {
                      return const Center(
                        child: Padding(
                          padding: EdgeInsets.all(16),
                          child: SizedBox(width: 24, height: 24, child: CircularProgressIndicator()),
                        ),
                      );
                    }
                    final n = _items[i];
                    final actor = (n['actor'] as Map?)?.cast<String, dynamic>() ?? {};
                    final avatar = (actor['avatar_url'] ?? '').toString();
                    final time = (n['time_ago'] ?? '').toString();
                    final read = n['read'] == true;

                    return InkWell(
                      onTap: () => _open(n),
                      borderRadius: BorderRadius.circular(12),
                      child: Container(
                        padding: const EdgeInsets.all(12),
                        decoration: BoxDecoration(
                          color: read ? Colors.white : const Color(0xFFF5F8FF),
                          borderRadius: BorderRadius.circular(12),
                          border: Border.all(color: Colors.black12),
                        ),
                        child: Row(
                          crossAxisAlignment: CrossAxisAlignment.center,
                          children: [
                            Avatar(url: avatar, size: 40),
                            const SizedBox(width: 12),
                            Expanded(
                              child: Column(
                                crossAxisAlignment: CrossAxisAlignment.start,
                                children: [
                                  Text(_title(n), style: const TextStyle(fontWeight: FontWeight.w700)),
                                  const SizedBox(height: 3),
                                  Text(time, style: const TextStyle(fontSize: 12, color: Colors.black54)),
                                ],
                              ),
                            ),
                            if (!read) const Icon(Icons.circle, size: 8),
                          ],
                        ),
                      ),
                    );
                  },
                ),
              ),
      ),
    );
  }
}

/// --- POST PREVIEW SHEET ----------------------------------------------------
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

  // Local friendly mapper (keeps this sheet self-contained).
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

  bool _isVideo(String url, String mediaType) {
    final t = mediaType.toLowerCase();
    if (t == 'video') return true;
    final u = url.toLowerCase();
    return u.endsWith('.mp4') || u.endsWith('.m4v') || u.endsWith('.mov') || u.endsWith('.webm');
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
        await showModalBottomSheet(
          context: context,
          isScrollControlled: true,
          builder: (_) => _PostCommentsQuick(postId: widget.postId),
        );
        Navigator.maybePop(context);
      }
    } catch (e) {
      if (!mounted) return;
      setState(() {
        _loading = false;
        _err = _friendly(e, fallback: 'Couldn’t load post.');
      });
    }
  }

  Future<void> _toggleLike() async {
    final p = _post;
    if (p == null) return;
    final id = p['id']?.toString() ?? widget.postId;
    final liked = p['liked'] == true;
    final likes = (p['likes'] ?? 0) as int;

    setState(() {
      _post = {...p, 'liked': !liked, 'likes': liked ? (likes - 1).clamp(0, 1 << 30) : likes + 1};
    });

    final r = await ApiService.postForm('like_toggle.php', {'post_id': id});
    if (r['ok'] != true && mounted) {
      setState(() => _post = p);
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Couldn’t update like. Please try again.')),
      );
    }
  }

  Future<void> _openComments() async {
    final p = _post;
    if (p == null) return;
    final before = (p['comments'] ?? 0) as int;
    final res = await showModalBottomSheet<Map<String, dynamic>>(
      context: context,
      isScrollControlled: true,
      builder: (_) => _PostCommentsQuick(postId: p['id'].toString()),
    );
    if ((res?['posted'] == true) && mounted) {
      setState(() => _post = {...p, 'comments': before + 1});
    }
  }

  @override
  Widget build(BuildContext context) {
    if (_loading) {
      return const SafeArea(child: SizedBox(height: 320, child: Center(child: CircularProgressIndicator())));
    }
    if (_err != null) {
      return SafeArea(
        child: Padding(
          padding: const EdgeInsets.all(16),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              Container(
                width: 40,
                height: 4,
                decoration: BoxDecoration(color: Colors.black12, borderRadius: BorderRadius.circular(2)),
              ),
              const SizedBox(height: 12),
              const Icon(Icons.info_outline),
              const SizedBox(height: 8),
              Text(_err!),
              const SizedBox(height: 8),
              OutlinedButton(onPressed: _load, child: const Text('Retry')),
            ],
          ),
        ),
      );
    }

    final p = _post!;
    final url = (p['media_url'] ?? '').toString();
    final caption = (p['caption'] ?? '').toString();
    final likes = (p['likes'] ?? 0) as int;
    final comments = (p['comments'] ?? 0) as int;
    final liked = p['liked'] == true;
    final mtype = (p['media_type'] ?? '').toString();

    return SafeArea(
      child: Padding(
        padding: const EdgeInsets.fromLTRB(16, 8, 16, 12),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Container(
              width: 40,
              height: 4,
              decoration: BoxDecoration(color: Colors.black12, borderRadius: BorderRadius.circular(2)),
            ),
            const SizedBox(height: 12),
            ClipRRect(
              borderRadius: BorderRadius.circular(12),
              child: _isVideo(url, mtype)
                  ? _InlineVideo(url: url)
                  : (url.isNotEmpty ? Image.network(url, fit: BoxFit.cover) : Container(height: 180, color: Colors.black12)),
            ),
            if (caption.isNotEmpty) ...[
              const SizedBox(height: 10),
              Align(alignment: Alignment.centerLeft, child: Text(caption)),
            ],
            const SizedBox(height: 8),
            const Divider(height: 1),
            const SizedBox(height: 2),
            Row(
              children: [
                IconButton(onPressed: _toggleLike, icon: Icon(liked ? Icons.favorite : Icons.favorite_border)),
                Text('$likes'),
                const SizedBox(width: 12),
                IconButton(onPressed: _openComments, icon: const Icon(Icons.mode_comment_outlined)),
                Text('$comments'),
                const Spacer(),
                TextButton(onPressed: _openComments, child: const Text('View comments')),
              ],
            ),
          ],
        ),
      ),
    );
  }
}

/// --- COMMENTS QUICK SHEET (read-only list) --------------------------------
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
                            Avatar(url: avatar, size: 28),
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

/// Minimal inline video (same feel as feed)
class _InlineVideo extends StatefulWidget {
  final String url;
  const _InlineVideo({required this.url});
  @override
  State<_InlineVideo> createState() => _InlineVideoState();
}

class _InlineVideoState extends State<_InlineVideo> {
  VideoPlayerController? _c;
  bool _init = false;
  bool _err = false;

  @override
  void initState() {
    super.initState();
    _c = VideoPlayerController.networkUrl(Uri.parse(widget.url))
      ..setLooping(true)
      ..initialize().then((_) {
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

  @override
  Widget build(BuildContext context) {
    if (_err) {
      return Container(
        height: 220,
        alignment: Alignment.center,
        color: const Color(0xFFF1F2F4),
        child: const Text('Cannot play video'),
      );
    }
    if (!_init) {
      return Container(
        height: 220,
        alignment: Alignment.center,
        color: const Color(0xFFF1F2F4),
        child: const SizedBox(height: 24, width: 24, child: CircularProgressIndicator()),
      );
    }

    final ar = _c!.value.aspectRatio == 0 ? 16 / 9 : _c!.value.aspectRatio;

    return Stack(
      alignment: Alignment.center,
      children: [
        AspectRatio(aspectRatio: ar, child: VideoPlayer(_c!)),
        Positioned.fill(
          child: Material(
            color: Colors.transparent,
            child: InkWell(
              onTap: () => setState(() {
                if (_c!.value.isPlaying) {
                  _c!.pause();
                } else {
                  _c!.play();
                }
              }),
            ),
          ),
        ),
        AnimatedOpacity(
          duration: const Duration(milliseconds: 200),
          opacity: _c!.value.isPlaying ? 0 : 1,
          child: Container(
            decoration: const BoxDecoration(color: Color(0x55000000)),
            child: const Icon(Icons.play_arrow, color: Colors.white, size: 64),
          ),
        ),
      ],
    );
  }
}
