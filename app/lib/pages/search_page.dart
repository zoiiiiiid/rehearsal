import 'dart:io';
import 'dart:async';
import 'package:flutter/material.dart';
import 'package:shared_preferences/shared_preferences.dart';
import '../services/api.dart';
import '../widgets/follow_button.dart';
import '../widgets/avatar.dart';
import 'public_profile_page.dart';

const double kBrandTop = 32.0;
const String _kHistoryKey = 'search_history_v1';
const int _kHistoryMax = 10;

class SearchPage extends StatefulWidget {
  const SearchPage({super.key});
  @override
  State<SearchPage> createState() => _SearchPageState();
}

class _SearchPageState extends State<SearchPage> {
  final TextEditingController _q = TextEditingController();
  List<Map<String, dynamic>> _items = [];
  bool _loading = false;
  String? _err;
  Timer? _deb;

  List<String> _history = [];

  @override
  void initState() {
    super.initState();
    _loadHistory();
  }

  @override
  void dispose() {
    _q.dispose();
    _deb?.cancel();
    super.dispose();
  }

  // User-safe error mapping (why: avoid surfacing backend/dev details)
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

  // ---------------- recent history ----------------
  Future<void> _loadHistory() async {
    final sp = await SharedPreferences.getInstance();
    setState(() => _history = sp.getStringList(_kHistoryKey) ?? []);
  }

  Future<void> _rememberQuery(String q) async {
    final query = q.trim();
    if (query.isEmpty) return;
    final sp = await SharedPreferences.getInstance();
    final list = sp.getStringList(_kHistoryKey) ?? [];
    list.removeWhere((e) => e.toLowerCase() == query.toLowerCase());
    list.insert(0, query);
    if (list.length > _kHistoryMax) list.removeRange(_kHistoryMax, list.length);
    await sp.setStringList(_kHistoryKey, list);
    setState(() => _history = list);
  }

  Future<void> _deleteHistoryItem(String q) async {
    final sp = await SharedPreferences.getInstance();
    final list = sp.getStringList(_kHistoryKey) ?? [];
    list.removeWhere((e) => e == q);
    await sp.setStringList(_kHistoryKey, list);
    setState(() => _history = list);
  }

  Future<void> _clearHistory() async {
    final sp = await SharedPreferences.getInstance();
    await sp.remove(_kHistoryKey);
    setState(() => _history = []);
  }

  // ---------------- searching ----------------
  void _onChanged(String _) {
    _deb?.cancel();
    _deb = Timer(const Duration(milliseconds: 350), _search);
  }

  Future<void> _search() async {
    final query = _q.text.trim();
    if (query.isEmpty) {
      setState(() {
        _items = [];
        _err = null;
        _loading = false;
      });
      return;
    }

    setState(() {
      _loading = true;
      _err = null;
    });

    try {
      final path = 'search_users.php?q=${Uri.encodeQueryComponent(query)}&page=1&limit=30';
      final res = await ApiService.get(path);
      if (!mounted) return;

      if (res['ok'] == true && res['items'] is List) {
        final list = (res['items'] as List)
            .cast<Map>()
            .map((e) => e.cast<String, dynamic>())
            .toList();
        setState(() {
          _items = list;
          _loading = false;
        });
        _rememberQuery(query);
      } else {
        setState(() {
          _loading = false;
          _err = 'Couldn’t load results. Please try again.';
        });
      }
    } catch (e) {
      if (!mounted) return;
      setState(() {
        _loading = false;
        _err = _friendly(e, fallback: 'Couldn’t load results. Please try again.');
      });
    }
  }

  // ---------------- UI ----------------
  @override
  Widget build(BuildContext context) {
    return Scaffold(
      body: SafeArea(
        child: Column(
          children: [
            Padding(
              padding: const EdgeInsets.fromLTRB(16, kBrandTop, 16, 8),
              child: TextField(
                controller: _q,
                onChanged: _onChanged,
                onSubmitted: (_) => _search(),
                decoration: InputDecoration(
                  hintText: 'Search people',
                  prefixIcon: const Icon(Icons.search),
                  suffixIcon: _q.text.isEmpty
                      ? null
                      : IconButton(
                          icon: const Icon(Icons.clear),
                          onPressed: () {
                            _q.clear();
                            setState(() {
                              _items = [];
                              _err = null;
                            });
                          },
                        ),
                  border: OutlineInputBorder(borderRadius: BorderRadius.circular(12)),
                ),
              ),
            ),
            if (_loading) const LinearProgressIndicator(minHeight: 2),
            Expanded(child: _body()),
          ],
        ),
      ),
    );
  }

  Widget _body() {
    if (_err != null) {
      return Center(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            const Icon(Icons.info_outline),
            const SizedBox(height: 6),
            Text(_err!, textAlign: TextAlign.center),
            const SizedBox(height: 8),
            OutlinedButton(onPressed: _search, child: const Text('Retry')),
          ],
        ),
      );
    }

    if (_q.text.trim().isEmpty && _items.isEmpty) {
      return _history.isEmpty ? _emptyHint() : _historyList();
    }

    if (_items.isEmpty) {
      return _emptyHint();
    }

    return ListView.separated(
      padding: const EdgeInsets.fromLTRB(0, 0, 0, 12),
      itemCount: _items.length,
      separatorBuilder: (_, __) => const SizedBox(height: 4),
      itemBuilder: (c, i) {
        final u = _items[i];
        final id = (u['id'] ?? '').toString();
        final name = (u['display_name'] ?? u['name'] ?? '').toString();
        final handle = (u['handle'] ?? (u['username'] != null ? '@${u['username']}' : '')).toString();
        final isMe = (u['is_me'] == true);
        final following = (u['is_following'] == true) || (u['following'] == true);
        final avatar = (u['avatar_url'] ?? '').toString();

        return ListTile(
          onTap: () => Navigator.push(c, MaterialPageRoute(builder: (_) => PublicProfilePage(userId: id))),
          leading: Avatar(url: avatar, size: 40),
          title: Text(name, style: const TextStyle(fontWeight: FontWeight.w600)),
          subtitle: handle.isNotEmpty ? Text(handle, style: const TextStyle(color: Colors.grey)) : null,
          trailing: FollowButton(
            userId: id,
            initialFollowing: following,
            isMe: isMe,
            onChanged: (_) {},
          ),
        );
      },
    );
  }

  Widget _emptyHint() => const Center(child: Text('Search users by name or @username'));

  Widget _historyList() {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        const Padding(
          padding: EdgeInsets.fromLTRB(16, 8, 16, 6),
          child: Text('Recent searches', style: TextStyle(fontWeight: FontWeight.w700)),
        ),
        Expanded(
          child: ListView.separated(
            itemCount: _history.length,
            separatorBuilder: (_, __) => const Divider(height: 1),
            itemBuilder: (_, i) {
              final q = _history[i];
              return Dismissible(
                key: ValueKey(q),
                direction: DismissDirection.endToStart,
                background: Container(
                  color: Colors.redAccent,
                  alignment: Alignment.centerRight,
                  padding: const EdgeInsets.symmetric(horizontal: 16),
                  child: const Icon(Icons.delete, color: Colors.white),
                ),
                onDismissed: (_) => _deleteHistoryItem(q),
                child: ListTile(
                  leading: const Icon(Icons.history),
                  title: Text(q),
                  onTap: () {
                    _q.text = q;
                    _search();
                  },
                  trailing: IconButton(
                    tooltip: 'Remove',
                    icon: const Icon(Icons.close),
                    onPressed: () => _deleteHistoryItem(q),
                  ),
                ),
              );
            },
          ),
        ),
        if (_history.isNotEmpty)
          Padding(
            padding: const EdgeInsets.fromLTRB(16, 8, 16, 16),
            child: Align(
              alignment: Alignment.centerRight,
              child: TextButton(
                onPressed: _clearHistory,
                child: const Text('Clear all'),
              ),
            ),
          ),
      ],
    );
  }
}
