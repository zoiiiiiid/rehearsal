// lib/pages/inbox_page.dart
import 'dart:io';
import 'package:flutter/material.dart';
import '../services/api.dart';
import 'chat_page.dart';

class InboxPage extends StatefulWidget {
  const InboxPage({super.key});
  @override
  State<InboxPage> createState() => _InboxPageState();
}

class _InboxPageState extends State<InboxPage> {
  final List<Map<String, dynamic>> _items = [];
  bool _loading = true;
  String? _err;

  @override
  void initState() {
    super.initState();
    _load();
  }

  // User-safe error mapping (why: no dev/internal details in UI).
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

  Future<void> _load() async {
    setState(() {
      _loading = true;
      _err = null;
      _items.clear();
    });

    try {
      final list = await ApiService.listConversations();
      if (!mounted) return;
      final parsed = (list as List? ?? const [])
          .cast<Map>()
          .map((e) => e.cast<String, dynamic>())
          .toList();
      setState(() {
        _items.addAll(parsed);
        _loading = false;
      });
    } catch (e) {
      if (!mounted) return;
      setState(() {
        _loading = false;
        _err = _friendly(e, fallback: 'Couldn’t load messages.');
      });
    }
  }

  Future<void> _openChat(Map<String, dynamic> it) async {
    final otherId = (it['other_user_id'] ?? '').toString();
    if (otherId.isEmpty) return;

    final convId = (it['id'] as num?)?.toInt();

    await Navigator.push(
      context,
      MaterialPageRoute(
        builder: (_) => ChatPage(
          otherUserId: otherId,
          conversationId: convId,
        ),
      ),
    );

    if (mounted) _load();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Messages')),
      body: _loading
          ? const Center(child: CircularProgressIndicator())
          : (_err != null)
              ? Center(
                  child: Column(
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      const Icon(Icons.info_outline),
                      const SizedBox(height: 8),
                      Text(_err!),
                      const SizedBox(height: 8),
                      OutlinedButton(onPressed: _load, child: const Text('Retry')),
                    ],
                  ),
                )
              : RefreshIndicator(
                  onRefresh: _load,
                  child: _items.isEmpty
                      ? ListView(
                          children: const [
                            SizedBox(height: 160),
                            Center(child: Text('No conversations')),
                          ],
                        )
                      : ListView.separated(
                          itemCount: _items.length,
                          separatorBuilder: (_, __) => const Divider(height: 1, thickness: 0.6),
                          itemBuilder: (_, i) {
                            final it = _items[i];
                            final name = (it['other_display_name'] ?? it['other_username'] ?? 'User').toString();
                            final avatar = (it['other_avatar'] ?? '').toString();
                            final last = (it['last_content'] ?? '').toString();
                            final unread = (it['unread'] as num?)?.toInt() ?? 0;

                            return ListTile(
                              leading: CircleAvatar(
                                backgroundImage: avatar.isNotEmpty ? NetworkImage(avatar) : null,
                                child: avatar.isEmpty ? const Icon(Icons.person) : null,
                              ),
                              title: Text(
                                name,
                                maxLines: 1,
                                overflow: TextOverflow.ellipsis,
                                style: const TextStyle(fontWeight: FontWeight.w700),
                              ),
                              subtitle: Text(
                                last,
                                maxLines: 1,
                                overflow: TextOverflow.ellipsis,
                              ),
                              trailing: unread > 0
                                  ? Container(
                                      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
                                      decoration: BoxDecoration(
                                        color: Colors.redAccent,
                                        borderRadius: BorderRadius.circular(999),
                                      ),
                                      child: Text(
                                        '$unread',
                                        style: const TextStyle(
                                          color: Colors.white,
                                          fontSize: 12,
                                          fontWeight: FontWeight.w800,
                                        ),
                                      ),
                                    )
                                  : null,
                              onTap: () => _openChat(it),
                            );
                          },
                        ),
                ),
    );
  }
}
