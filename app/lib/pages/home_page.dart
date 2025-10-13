import 'dart:async';
import 'dart:io';
import 'package:flutter/material.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:video_player/video_player.dart';
import '../services/api.dart';
import '../widgets/avatar.dart';
import 'inbox_page.dart';

const double _kTopBarHeight = 56.0;

const Map<String, String> kSkillLabels = {
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

class HomePage extends StatefulWidget {
  const HomePage({super.key});
  @override
  State<HomePage> createState() => _HomePageState();
}

class _HomePageState extends State<HomePage> {
  final List<Map<String, dynamic>> _items = [];
  bool _loading = true;
  String? _err;
  Map<String, dynamic>? _lastRes; // kept only for internal decisions
  int _page = 1;
  bool _hasMore = true;

  int _unread = 0;
  Timer? _badgeTimer;

  String _skill = 'all';
  final ScrollController _feedScroll = ScrollController();

  @override
  void initState() {
    super.initState();
    _load(reset: true);
    _loadUnread();
    _badgeTimer = Timer.periodic(const Duration(seconds: 25), (_) => _loadUnread());
  }

  @override
  void dispose() {
    _badgeTimer?.cancel();
    _feedScroll.dispose();
    super.dispose();
  }

  Future<void> refreshFromTab() async {
    if (_feedScroll.hasClients && _feedScroll.offset > 100) {
      await _feedScroll.animateTo(0, duration: const Duration(milliseconds: 300), curve: Curves.easeOut);
    }
    await _pull();
  }

  Future<void> _pull() async {
    await _load(reset: true);
    await _loadUnread();
  }

  Future<void> _load({bool reset = false}) async {
    if (reset) {
      setState(() {
        _loading = true;
        _err = null;
        _lastRes = null;
        _page = 1;
        _hasMore = true;
        _items.clear();
      });
    } else {
      if (!_hasMore) return;
    }

    final f = _skill == 'all' ? '' : '&skill=${Uri.encodeQueryComponent(_skill)}';
    var res = await ApiService.get('feed.php?page=$_page&limit=10$f');
    _lastRes = res;

    // Why: fallback for servers that expect token in query.
    if (res['ok'] != true && (res['error'] == 'NO_TOKEN' || res['error'] == 'USER_REQUIRED')) {
      final sp = await SharedPreferences.getInstance();
      final t = sp.getString('token');
      if (t != null && t.isNotEmpty) {
        res = await ApiService.get('feed.php?page=$_page&limit=10$f&token=${Uri.encodeQueryComponent(t)}');
        _lastRes = res;
      }
    }

    if (!mounted) return;

    final hasItems = res['items'] is List;
    if ((res['ok'] == true || hasItems) && hasItems) {
      final list = (res['items'] as List).cast<Map>().map((e) => e.cast<String, dynamic>()).toList();
      setState(() {
        _items.addAll(list);
        final total = (res['total'] as int?) ?? _items.length;
        _hasMore = _items.length < total;
        _page += 1;
        _loading = false;
        _err = null;
      });
    } else {
      final errCode = (res['error'] ?? '').toString();
      if (errCode == 'NO_TOKEN') {
        if (!mounted) return;
        Navigator.of(context).pushReplacementNamed('/login');
        return;
      }
      setState(() {
        _loading = false;
        _err = _friendly(Exception(errCode.isEmpty ? 'feed_failed' : errCode), fallback: 'Couldn’t load feed.');
      });
    }
  }

  // ---- Unread notifications badge
  Future<void> _loadUnread() async {
    final r = await ApiService.get('notifications_unread.php');
    if (!mounted) return;
    if (r['ok'] == true) {
      setState(() => _unread = (r['unread'] as int?) ?? 0);
    }
  }

  Future<void> _openNotifications() async {
    try {
      await Navigator.of(context).pushNamed('/notifications');
    } catch (_) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Can’t open notifications right now.')),
      );
      return;
    }
    if (mounted) _loadUnread();
  }

  Future<void> _openMessages() async {
    try {
      await Navigator.push(context, MaterialPageRoute(builder: (_) => const InboxPage()));
    } catch (_) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Can’t open messages right now.')),
      );
    }
  }

  Future<void> _changeSkill(String s) async {
    if (s == _skill) return;
    setState(() => _skill = s);
    _load(reset: true);
  }

  // ---- Likes
  Future<void> _toggleLike(Map<String, dynamic> p, int index) async {
    final id = p['id']?.toString();
    if (id == null) return;
    final prevLiked = p['liked'] == true;
    final prevLikes = (p['likes'] ?? 0) as int;

    // optimistic
    setState(() {
      _items[index] = {
        ...p,
        'liked': !prevLiked,
        'likes': prevLiked ? (prevLikes - 1).clamp(0, 1 << 30) : prevLikes + 1,
      };
    });

    final res = await ApiService.postForm('like_toggle.php', {'post_id': id});
    if (res['ok'] != true && mounted) {
      setState(() {
        _items[index] = {...p};
      });
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Couldn’t update like. Please try again.')),
      );
    }
  }

  // ---- Delete Post
  Future<void> _deletePost(Map<String, dynamic> p, int index) async {
    final id = p['id']?.toString();
    if (id == null) return;

    final yes = await showDialog<bool>(
      context: context,
      builder: (c) => AlertDialog(
        title: const Text('Delete post?'),
        content: const Text('This cannot be undone.'),
        actions: [
          TextButton(onPressed: () => Navigator.pop(c, false), child: const Text('Cancel')),
          FilledButton(onPressed: () => Navigator.pop(c, true), child: const Text('Delete')),
        ],
      ),
    );
    if (yes != true) return;

    final res = await ApiService.postForm('post_delete.php', {'post_id': id});
    if (!mounted) return;

    if (res['ok'] == true) {
      setState(() => _items.removeAt(index));
      ScaffoldMessenger.of(context).showSnackBar(const SnackBar(content: Text('Post deleted')));
    } else {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Couldn’t delete post. Please try again.')),
      );
    }
  }

  // ---- Comments (inline sheet)
  Future<void> _openComments(Map<String, dynamic> p, int index) async {
    final postId = p['id']?.toString();
    if (postId == null) return;

    final result = await showModalBottomSheet<Map<String, dynamic>>(
      context: context,
      isScrollControlled: true,
      backgroundColor: Colors.white,
      builder: (ctx) => _CommentsSheet(postId: postId, openUser: _openUserById),
    );

    if (mounted && (result?['posted'] == true)) {
      final prev = (p['comments'] ?? 0) as int;
      setState(() => _items[index] = {...p, 'comments': prev + 1});
    }
  }

  // ---- Navigation helpers
  String? _userId(Map<String, dynamic> p) {
    final u = p['user'];
    if (u is Map) return (u['id'] ?? u['user_id'])?.toString();
    return (p['user_id'] ?? p['author_id'] ?? p['uid'])?.toString();
  }

  String _userName(Map<String, dynamic> p) {
    final u = p['user'];
    if (u is Map) return (u['display_name'] ?? u['name'] ?? '').toString();
    return (p['user_name'] ?? p['name'] ?? '').toString();
  }

  String _userUsername(Map<String, dynamic> p) {
    final u = p['user'];
    if (u is Map) return (u['username'] ?? '').toString();
    return (p['username'] ?? '').toString();
  }

  String _userAvatar(Map<String, dynamic> p) {
    final u = p['user'];
    if (u is Map) return (u['avatar_url'] ?? '').toString();
    return (p['avatar_url'] ?? '').toString();
  }

  Future<void> _openUserById(String userId) async {
    try {
      await Navigator.of(context).pushNamed('/user', arguments: {'id': userId});
    } catch (_) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Can’t open profile right now.')),
      );
    }
  }

  Widget _userHeader(Map<String, dynamic> p, int index) {
    final uid = _userId(p);
    final name = _userName(p);
    final username = _userUsername(p);
    final avatarUrl = _userAvatar(p);
    final canDelete = p['can_delete'] == true;
    final skill = (p['skill'] ?? '').toString();

    final content = Row(
      crossAxisAlignment: CrossAxisAlignment.center,
      children: [
        Avatar(url: avatarUrl, size: 36),
        const SizedBox(width: 10),
        Expanded(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(name, style: const TextStyle(fontWeight: FontWeight.w700)),
              if (username.isNotEmpty) const SizedBox(height: 1),
              if (username.isNotEmpty) Text('@$username', style: const TextStyle(color: Colors.black54)),
            ],
          ),
        ),
        if (skill.isNotEmpty)
          Container(
            padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
            decoration: BoxDecoration(color: Colors.black12, borderRadius: BorderRadius.circular(999)),
            child: Text(skill.toUpperCase(), style: const TextStyle(fontSize: 11, fontWeight: FontWeight.w700)),
          ),
        if (canDelete)
          PopupMenuButton<String>(
            icon: const Icon(Icons.more_horiz),
            onSelected: (v) {
              if (v == 'delete') _deletePost(p, index);
            },
            itemBuilder: (c) => const [
              PopupMenuItem(value: 'delete', child: Text('Delete post')),
            ],
          ),
      ],
    );

    return uid == null ? content : InkWell(onTap: () => _openUserById(uid), child: content);
  }

  bool _isVideo(Map<String, dynamic> p) {
    final type = (p['media_type'] ?? '').toString().toLowerCase();
    if (type == 'video') return true;
    final url = (p['media_url'] ?? '').toString().toLowerCase();
    return url.endsWith('.mp4') || url.endsWith('.m4v') || url.endsWith('.mov') || url.endsWith('.webm');
  }

  // ---------- Top bar (brand + actions)
  Widget _topBar() {
    return SafeArea(
      bottom: false,
      child: Container(
        height: _kTopBarHeight,
        padding: const EdgeInsets.symmetric(horizontal: 12),
        decoration: const BoxDecoration(
          color: Colors.white,
          border: Border(bottom: BorderSide(color: Colors.black12)),
        ),
        child: Row(
          children: [
            const _BrandWordmark(),
            const Spacer(),
            IconButton(
              tooltip: 'Messages',
              onPressed: _openMessages,
              icon: const Icon(Icons.chat_bubble_outline),
            ),
            Stack(
              clipBehavior: Clip.none,
              children: [
                IconButton(
                  tooltip: 'Notifications',
                  onPressed: _openNotifications,
                  icon: const Icon(Icons.notifications_none),
                ),
                if (_unread > 0) const Positioned(right: 8, top: 8, child: _UnreadDot()),
              ],
            ),
          ],
        ),
      ),
    );
  }

  // ---- UI
  @override
  Widget build(BuildContext context) {
    if (_loading && _items.isEmpty) {
      return const Center(child: CircularProgressIndicator());
    }
    if (_err != null) {
      return Center(
        child: Padding(
          padding: const EdgeInsets.symmetric(horizontal: 16),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              const Icon(Icons.info_outline),
              const SizedBox(height: 8),
              Text(_err!),
              const SizedBox(height: 8),
              OutlinedButton(onPressed: () => _load(reset: true), child: const Text('Retry')),
            ],
          ),
        ),
      );
    }

    final chips = SingleChildScrollView(
      scrollDirection: Axis.horizontal,
      padding: const EdgeInsets.fromLTRB(12, 8, 12, 8),
      child: Row(
        children: kSkillLabels.entries.map((e) {
          final selected = _skill == e.key;
          return Padding(
            padding: const EdgeInsets.only(right: 8),
            child: ChoiceChip(
              label: Text(e.value),
              selected: selected,
              onSelected: (_) => _changeSkill(e.key),
              shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(20)),
            ),
          );
        }).toList(),
      ),
    );

    return Stack(
      children: [
        RefreshIndicator(
          onRefresh: _pull,
          child: NotificationListener<ScrollNotification>(
            onNotification: (n) {
              if (n.metrics.pixels >= n.metrics.maxScrollExtent - 200) _load();
              return false;
            },
            child: ListView.separated(
              controller: _feedScroll,
              itemCount: (_items.length + (_hasMore ? 1 : 0)) + 1,
              padding: const EdgeInsets.only(top: _kTopBarHeight, bottom: 24),
              separatorBuilder: (_, __) => const SizedBox(height: 12),
              itemBuilder: (_, i) {
                if (i == 0) return chips;

                final idx = i - 1;

                if (idx >= _items.length) {
                  return const Padding(
                    padding: EdgeInsets.all(16),
                    child: Center(child: SizedBox(height: 24, width: 24, child: CircularProgressIndicator())),
                  );
                }
                final p = _items[idx];
                final url = (p['media_url'] ?? '').toString();
                final caption = (p['caption'] ?? '').toString();
                final likes = (p['likes'] ?? 0) as int;
                final comments = (p['comments'] ?? 0) as int;
                final liked = p['liked'] == true;

                return Card(
                  elevation: 0,
                  margin: const EdgeInsets.symmetric(horizontal: 12),
                  shape: RoundedRectangleBorder(
                    borderRadius: BorderRadius.circular(14),
                    side: const BorderSide(color: Colors.black12),
                  ),
                  child: Padding(
                    padding: const EdgeInsets.all(12),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        _userHeader(p, idx),
                        const SizedBox(height: 10),
                        _isVideo(p)
                            ? _InlineVideo(url: url)
                            : (url.isNotEmpty
                                ? ClipRRect(
                                    borderRadius: BorderRadius.circular(12),
                                    child: Image.network(url, fit: BoxFit.cover),
                                  )
                                : Container(
                                    height: 180,
                                    decoration: BoxDecoration(
                                      color: Colors.black12,
                                      borderRadius: BorderRadius.circular(12),
                                    ),
                                  )),
                        if (caption.isNotEmpty) ...[
                          const SizedBox(height: 8),
                          Text(caption, maxLines: 2, overflow: TextOverflow.ellipsis),
                        ],
                        const SizedBox(height: 8),
                        const Divider(height: 1),
                        const SizedBox(height: 2),
                        Row(
                          children: [
                            IconButton(
                              onPressed: () => _toggleLike(p, idx),
                              icon: Icon(liked ? Icons.favorite : Icons.favorite_border),
                            ),
                            Text('$likes'),
                            const SizedBox(width: 12),
                            IconButton(
                              onPressed: () => _openComments(p, idx),
                              icon: const Icon(Icons.mode_comment_outlined),
                            ),
                            Text('$comments'),
                          ],
                        ),
                      ],
                    ),
                  ),
                );
              },
            ),
          ),
        ),
        _topBar(),
      ],
    );
  }
}

class _BrandWordmark extends StatelessWidget {
  const _BrandWordmark();

  @override
  Widget build(BuildContext context) {
    return const Text(
      'Re:hearsal',
      style: TextStyle(
        fontSize: 22,
        fontWeight: FontWeight.w800,
        fontStyle: FontStyle.italic,
        letterSpacing: .2,
      ),
    );
  }
}

class _UnreadDot extends StatelessWidget {
  const _UnreadDot();
  @override
  Widget build(BuildContext context) {
    return Container(
      width: 9,
      height: 9,
      decoration: const BoxDecoration(color: Colors.red, shape: BoxShape.circle),
    );
  }
}

/// Small inline video player with play/pause overlay.
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
        decoration: BoxDecoration(color: Colors.black12, borderRadius: BorderRadius.circular(12)),
        child: const Text('Cannot play video'),
      );
    }
    if (!_init) {
      return Container(
        height: 220,
        alignment: Alignment.center,
        decoration: BoxDecoration(color: Colors.black12, borderRadius: BorderRadius.circular(12)),
        child: const SizedBox(height: 24, width: 24, child: CircularProgressIndicator()),
      );
    }

    final ar = _c!.value.aspectRatio == 0 ? 16 / 9 : _c!.value.aspectRatio;

    return ClipRRect(
      borderRadius: BorderRadius.circular(12),
      child: Stack(
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
      ),
    );
  }
}

// ===== Inline comments sheet
class _CommentsSheet extends StatefulWidget {
  final String postId;
  final Future<void> Function(String userId) openUser;
  const _CommentsSheet({required this.postId, required this.openUser});

  @override
  State<_CommentsSheet> createState() => _CommentsSheetState();
}

class _CommentsSheetState extends State<_CommentsSheet> {
  final List<Map<String, dynamic>> _items = [];
  final TextEditingController _input = TextEditingController();
  bool _loading = true;
  String? _err;
  int _page = 1;
  bool _hasMore = true;
  bool _posting = false;

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

    final res = await ApiService.get(
      'comments_list.php?post_id=${Uri.encodeQueryComponent(widget.postId)}&page=$_page&limit=20',
    );
    if (!mounted) return;

    final hasItems = res['items'] is List;
    if ((res['ok'] == true || hasItems) && hasItems) {
      final list = (res['items'] as List).cast<Map>().map((e) => e.cast<String, dynamic>()).toList();
      setState(() {
        _items.addAll(list);
        final total = (res['count'] as int?) ?? _items.length;
        _hasMore = _items.length < total;
        _page += 1;
        _loading = false;
      });
    } else {
      setState(() {
        _loading = false;
        _err = _friendly(Exception(res['error'] ?? 'comments_failed'), fallback: 'Couldn’t load comments.');
      });
    }
  }

  Future<void> _post() async {
    final body = _input.text.trim();
    if (body.isEmpty || _posting) return;
    setState(() => _posting = true);

    final res = await ApiService.postForm('comment_create.php', {
      'post_id': widget.postId,
      'body': body,
    });

    if (!mounted) return;

    if (res['ok'] == true && res['item'] is Map) {
      final item = (res['item'] as Map).cast<String, dynamic>();
      setState(() {
        _items.insert(0, item);
        _posting = false;
        _input.clear();
      });
      Navigator.maybeOf(context)?.pop({'posted': true});
    } else {
      setState(() => _posting = false);
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Couldn’t post comment. Please try again.')),
      );
    }
  }

  Future<void> _deleteComment(Map<String, dynamic> c, int index) async {
    final id = c['id']?.toString();
    if (id == null) return;

    final yes = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('Delete comment?'),
        content: const Text('This cannot be undone.'),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx, false), child: const Text('Cancel')),
          FilledButton(onPressed: () => Navigator.pop(ctx, true), child: const Text('Delete')),
        ],
      ),
    );
    if (yes != true) return;

    final res = await ApiService.postForm('comment_delete.php', {'comment_id': id});
    if (!mounted) return;

    if (res['ok'] == true) {
      setState(() => _items.removeAt(index));
      ScaffoldMessenger.of(context).showSnackBar(const SnackBar(content: Text('Comment deleted')));
    } else {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Couldn’t delete comment. Please try again.')),
      );
    }
  }

  String? _uid(Map<String, dynamic> c) {
    final u = c['user'];
    if (u is Map) return (u['id'] ?? u['user_id'])?.toString();
    return (c['user_id'] ?? c['uid'])?.toString();
  }

  String _name(Map<String, dynamic> c) {
    final u = c['user'];
    if (u is Map) return (u['display_name'] ?? u['name'] ?? '').toString();
    return (c['user_name'] ?? c['name'] ?? '').toString();
  }

  String _username(Map<String, dynamic> c) {
    final u = c['user'];
    if (u is Map) return (u['username'] ?? '').toString();
    return (c['username'] ?? '').toString();
  }

  String _avatar(Map<String, dynamic> c) {
    final u = c['user'];
    if (u is Map) return (u['avatar_url'] ?? '').toString();
    return (c['avatar_url'] ?? '').toString();
  }

  String _time(Map<String, dynamic> c) => (c['time_ago'] ?? c['time'] ?? '').toString();

  Widget _row(Map<String, dynamic> c, int index) {
    final uid = _uid(c);
    final name = _name(c);
    final username = _username(c);
    final text = (c['body'] ?? '').toString();
    final avatar = _avatar(c);
    final canDelete = c['can_delete'] == true;

    final header = Row(
      children: [
        InkWell(
          onTap: (uid == null || uid.isEmpty) ? null : () => widget.openUser(uid),
          child: Row(
            children: [
              Avatar(url: avatar, size: 28),
              const SizedBox(width: 8),
              Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(name, style: const TextStyle(fontWeight: FontWeight.w700)),
                  Row(
                    children: [
                      if (username.isNotEmpty) const SizedBox(height: 1),
                      if (username.isNotEmpty) Text('@$username', style: const TextStyle(color: Colors.black54)),
                      const SizedBox(width: 8),
                      Text(_time(c), style: const TextStyle(color: Colors.black45, fontSize: 12)),
                    ],
                  ),
                ],
              ),
            ],
          ),
        ),
        const Spacer(),
        if (canDelete)
          PopupMenuButton<String>(
            onSelected: (v) {
              if (v == 'delete') _deleteComment(c, index);
            },
            itemBuilder: (ctx) => const [PopupMenuItem(value: 'delete', child: Text('Delete'))],
            icon: const Icon(Icons.more_horiz),
          ),
      ],
    );

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        header,
        const SizedBox(height: 6),
        Text(text),
      ],
    );
  }

  @override
  Widget build(BuildContext context) {
    if (_loading) {
      return const SizedBox(height: 240, child: Center(child: CircularProgressIndicator()));
    }
    if (_err != null) {
      return Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          children: [
            const SizedBox(height: 12),
            const Icon(Icons.info_outline),
            const SizedBox(height: 8),
            Text(_err!),
            const SizedBox(height: 8),
            OutlinedButton(onPressed: () => _load(reset: true), child: const Text('Retry')),
          ],
        ),
      );
    }

    final inputBar = SafeArea(
      top: false,
      child: Row(
        children: [
          Expanded(
            child: TextField(
              controller: _input,
              minLines: 1,
              maxLines: 4,
              decoration: const InputDecoration(
                hintText: 'Write a comment…',
                border: OutlineInputBorder(),
                isDense: true,
              ),
            ),
          ),
          const SizedBox(width: 8),
          IconButton(
            onPressed: _posting ? null : _post,
            icon: _posting
                ? const SizedBox(width: 18, height: 18, child: CircularProgressIndicator(strokeWidth: 2))
                : const Icon(Icons.send),
          ),
        ],
      ),
    );

    return DraggableScrollableSheet(
      initialChildSize: 0.8,
      minChildSize: 0.5,
      maxChildSize: 0.95,
      expand: false,
      builder: (_, scroll) => Padding(
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
              child: NotificationListener<ScrollNotification>(
                onNotification: (n) {
                  if (n.metrics.pixels >= n.metrics.maxScrollExtent - 120) _load();
                  return false;
                },
                child: ListView.separated(
                  controller: scroll,
                  itemCount: _items.length,
                  separatorBuilder: (_, __) => const SizedBox(height: 12),
                  itemBuilder: (_, i) => _row(_items[i], i),
                ),
              ),
            ),
            const SizedBox(height: 8),
            inputBar,
          ],
        ),
      ),
    );
  }
}
