// lib/pages/follow_list_page.dart
import 'dart:async';
import 'dart:io';
import 'package:flutter/material.dart';
import '../services/api.dart';
import '../widgets/follow_button.dart';
import 'public_profile_page.dart';

class FollowListPage extends StatefulWidget {
  final String? userId;
  final String initialType;
  const FollowListPage({super.key, this.userId, this.initialType = 'followers'});

  @override
  State<FollowListPage> createState() => _FollowListPageState();
}

class _FollowListPageState extends State<FollowListPage> with SingleTickerProviderStateMixin {
  late TabController _tab;
  final TextEditingController _q = TextEditingController();

  List<Map<String, dynamic>> _items = [];
  bool _loading = true;
  String? _err;
  int _page = 1;
  bool _hasMore = true;

  @override
  void initState() {
    super.initState();
    _tab = TabController(length: 2, vsync: this, initialIndex: widget.initialType == 'following' ? 1 : 0)
      ..addListener(() {
        if (_tab.indexIsChanging) return;
        _refresh();
      });
    _refresh();
  }

  @override
  void dispose() {
    _tab.dispose();
    _q.dispose();
    super.dispose();
  }

  // User-safe error mapping.
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

  Future<void> _refresh() async {
    setState(() {
      _loading = true;
      _err = null;
      _page = 1;
      _hasMore = true;
      _items = [];
    });
    await _loadMore(reset: true);
  }

  Future<void> _loadMore({bool reset = false}) async {
    if (!_hasMore && !reset) return;

    final type = _tab.index == 1 ? 'following' : 'followers';
    final qp = <String, String>{
      'type': type,
      'limit': '20',
      'page': _page.toString(),
    };
    final query = _q.text.trim();
    if (query.isNotEmpty) qp['q'] = query;
    if (widget.userId != null && widget.userId!.isNotEmpty) qp['user_id'] = widget.userId!;

    try {
      final path = Uri(path: 'follow_list.php', queryParameters: qp).toString();
      final res = await ApiService.get(path);
      if (!mounted) return;

      if (res['ok'] == true && res['items'] is List) {
        final list = (res['items'] as List).cast<Map>().map((e) => e.cast<String, dynamic>()).toList();
        setState(() {
          _items.addAll(list);
          _page += 1;
          _hasMore = _items.length < (res['total'] as int? ?? 0);
          _loading = false;
          _err = null;
        });
      } else {
        setState(() {
          _loading = false;
          _err = _friendly(Exception('load_failed'), fallback: 'Couldn’t load connections.');
        });
      }
    } catch (e) {
      if (!mounted) return;
      setState(() {
        _loading = false;
        _err = _friendly(e, fallback: 'Couldn’t load connections.');
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text('Connections'),
        bottom: TabBar(
          controller: _tab,
          tabs: const [Tab(text: 'Followers'), Tab(text: 'Following')],
        ),
      ),
      body: RefreshIndicator(
        onRefresh: _refresh,
        child: Column(
          children: [
            Padding(
              padding: const EdgeInsets.fromLTRB(16, 12, 16, 8),
              child: TextField(
                controller: _q,
                onChanged: (_) => setState(() {}), // keeps clear button in sync
                onSubmitted: (_) => _refresh(),
                decoration: InputDecoration(
                  hintText: 'Search people',
                  prefixIcon: const Icon(Icons.search),
                  suffixIcon: _q.text.isEmpty
                      ? null
                      : IconButton(
                          icon: const Icon(Icons.clear),
                          onPressed: () {
                            _q.clear();
                            _refresh();
                          },
                        ),
                  border: OutlineInputBorder(borderRadius: BorderRadius.circular(12)),
                ),
              ),
            ),
            Expanded(child: _body()),
          ],
        ),
      ),
    );
  }

  Widget _body() {
    if (_loading && _items.isEmpty) return const Center(child: CircularProgressIndicator());
    if (_err != null) {
      return Center(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            const Icon(Icons.info_outline),
            const SizedBox(height: 8),
            Text(_err!),
            const SizedBox(height: 8),
            OutlinedButton(onPressed: _refresh, child: const Text('Retry')),
          ],
        ),
      );
    }
    if (_items.isEmpty) return const Center(child: Text('No connections yet'));

    return NotificationListener<ScrollNotification>(
      onNotification: (n) {
        if (n.metrics.pixels >= n.metrics.maxScrollExtent - 200) _loadMore();
        return false;
      },
      child: ListView.separated(
        itemCount: _items.length + (_hasMore ? 1 : 0),
        separatorBuilder: (_, __) => const SizedBox(height: 4),
        itemBuilder: (c, i) {
          if (i >= _items.length) {
            return const Padding(
              padding: EdgeInsets.all(16),
              child: Center(
                child: SizedBox(height: 24, width: 24, child: CircularProgressIndicator()),
              ),
            );
          }
          final u = _items[i];
          final id = (u['id'] ?? '').toString();
          final name = (u['display_name'] ?? u['name'] ?? '').toString();
          final handle = (u['handle'] ?? (u['username'] != null ? '@${u['username']}' : '')).toString();
          final isMe = (u['is_me'] == true);
          final following = (u['following'] == true) || (u['is_following'] == true);

          return ListTile(
            onTap: () => Navigator.push(c, MaterialPageRoute(builder: (_) => PublicProfilePage(userId: id))),
            leading: const CircleAvatar(),
            title: Text(name, style: const TextStyle(fontWeight: FontWeight.w600)),
            subtitle: handle.isNotEmpty ? Text(handle, style: const TextStyle(color: Colors.grey)) : null,
            trailing: FollowButton(
              userId: id,
              initialFollowing: following,
              isMe: isMe,
              onChanged: (_) {}, // parent can refresh if needed
            ),
          );
        },
      ),
    );
  }
}
