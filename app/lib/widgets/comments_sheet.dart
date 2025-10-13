// lib/widgets/comments_sheet.dart
import 'dart:async';
import 'package:flutter/material.dart';
import '../services/api.dart';
import '../pages/public_profile_page.dart';
import '../widgets/avatar.dart';

class CommentsSheet extends StatefulWidget {
  final String postId;
  const CommentsSheet({super.key, required this.postId});

  @override
  State<CommentsSheet> createState() => _CommentsSheetState();
}

class _CommentsSheetState extends State<CommentsSheet> {
  final List<Map<String, dynamic>> _items = [];
  final TextEditingController _input = TextEditingController();

  bool _loading = true;
  bool _loadingPage = false; // prevent duplicate page fetches
  String? _err;
  int _page = 1;
  bool _hasMore = true;
  bool _posting = false;

  @override
  void initState() {
    super.initState();
    _load(reset: true);
  }

  @override
  void dispose() {
    _input.dispose();
    super.dispose();
  }

  Future<void> _load({bool reset = false}) async {
    if (reset) {
      setState(() {
        _loading = true;
        _loadingPage = true;
        _err = null;
        _page = 1;
        _hasMore = true;
        _items.clear();
      });
    } else {
      if (!_hasMore || _loadingPage) return;
      setState(() => _loadingPage = true);
    }

    final res = await ApiService.get(
      'comments_list.php?post_id=${Uri.encodeQueryComponent(widget.postId)}&page=$_page&limit=20',
    );
    if (!mounted) return;

    final hasItems = res['items'] is List;
    if ((res['ok'] == true || hasItems) && hasItems) {
      final list = (res['items'] as List)
          .cast<Map>()
          .map((e) => e.cast<String, dynamic>())
          .toList();
      setState(() {
        _items.addAll(list);
        // some backends use "total", others "count"
        final total = (res['total'] as int?) ?? (res['count'] as int?) ?? _items.length;
        _hasMore = _items.length < total;
        _page += 1;
        _loading = false;
        _loadingPage = false;
      });
    } else {
      setState(() {
        _loading = false;
        _loadingPage = false;
        _err = (res['error'] ?? 'UNKNOWN').toString();
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
      // Let caller update count optimistically if they listen for it.
      Navigator.maybeOf(context)?.pop({'posted': true});
    } else {
      setState(() => _posting = false);
      final msg = (res['error'] ?? 'UNKNOWN').toString();
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text('Failed: $msg')),
      );
    }

    // remove keyboard so the new comment is clearly visible
    FocusScope.of(context).unfocus();
  }

  Future<void> _deleteComment(Map<String, dynamic> c, int index) async {
    final id = c['id']?.toString();
    if (id == null || id.isEmpty) return;

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
      final msg = (res['error'] ?? 'UNKNOWN').toString();
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text('Delete failed: $msg')));
    }
  }

  // -------- robust getters --------
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

  String _time(Map<String, dynamic> c) =>
      (c['time_ago'] ?? c['time'] ?? '').toString();

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
          onTap: (uid == null || uid.isEmpty)
              ? null
              : () => Navigator.push(
                    context,
                    MaterialPageRoute(builder: (_) => PublicProfilePage(userId: uid)),
                  ),
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
                      if (username.isNotEmpty)
                        Text('@$username', style: const TextStyle(color: Colors.black54)),
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
            itemBuilder: (ctx) => const [
              PopupMenuItem(value: 'delete', child: Text('Delete')),
            ],
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
    final inputBar = SafeArea(
      top: false,
      child: Row(
        children: [
          Expanded(
            child: TextField(
              controller: _input,
              minLines: 1,
              maxLines: 4,
              onSubmitted: (_) => _post(),
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
                ? const SizedBox(
                    width: 18,
                    height: 18,
                    child: CircularProgressIndicator(strokeWidth: 2),
                  )
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
      builder: (c, scroll) {
        if (_loading) {
          return const Center(child: CircularProgressIndicator());
        }
        if (_err != null) {
          return Padding(
            padding: const EdgeInsets.all(16),
            child: Column(
              children: [
                const SizedBox(height: 12),
                Text(_err!, style: const TextStyle(color: Colors.red)),
                const SizedBox(height: 8),
                OutlinedButton(
                  onPressed: () => _load(reset: true),
                  child: const Text('Retry'),
                ),
              ],
            ),
          );
        }

        final empty = _items.isEmpty
            ? const Padding(
                padding: EdgeInsets.only(top: 24),
                child: Text('No comments yet. Be the first to comment!'),
              )
            : const SizedBox.shrink();

        return Padding(
          padding: const EdgeInsets.fromLTRB(16, 8, 16, 8),
          child: Column(
            children: [
              Container(
                width: 40,
                height: 4,
                decoration: BoxDecoration(
                  color: Colors.black12,
                  borderRadius: BorderRadius.circular(2),
                ),
              ),
              const SizedBox(height: 12),
              Expanded(
                child: NotificationListener<ScrollNotification>(
                  onNotification: (n) {
                    if (n.metrics.pixels >= n.metrics.maxScrollExtent - 120) {
                      _load(); // guarded by _loadingPage/_hasMore
                    }
                    return false;
                  },
                  child: _items.isEmpty
                      ? ListView(
                          controller: scroll,
                          padding: const EdgeInsets.only(top: 8),
                          children: [empty],
                        )
                      : ListView.separated(
                          controller: scroll,
                          itemCount: _items.length + (_hasMore ? 1 : 0),
                          separatorBuilder: (_, __) => const SizedBox(height: 12),
                          itemBuilder: (_, i) {
                            if (i >= _items.length) {
                              // loading footer
                              return const Padding(
                                padding: EdgeInsets.symmetric(vertical: 12),
                                child: Center(
                                  child: SizedBox(
                                    width: 20,
                                    height: 20,
                                    child: CircularProgressIndicator(strokeWidth: 2),
                                  ),
                                ),
                              );
                            }
                            return _row(_items[i], i);
                          },
                        ),
                ),
              ),
              const SizedBox(height: 8),
              inputBar,
            ],
          ),
        );
      },
    );
  }
}
