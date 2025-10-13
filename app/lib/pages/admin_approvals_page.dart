import 'dart:io';
import 'package:flutter/material.dart';
import '../services/api.dart';

class AdminApprovalsPage extends StatefulWidget {
  const AdminApprovalsPage({super.key});

  @override
  State<AdminApprovalsPage> createState() => _AdminApprovalsPageState();
}

class _AdminApprovalsPageState extends State<AdminApprovalsPage> {
  final TextEditingController _search = TextEditingController();
  bool _loading = true;
  String? _err; // user-friendly message
  List<Map<String, dynamic>> _items = [];
  String? _busyUserId; // row-level pending state

  @override
  void initState() {
    super.initState();
    _load();
  }

  // Maps technical errors to user-safe messages.
  String _friendly(Object error, {String? fallback}) {
    final fb = fallback ?? 'Something went wrong. Please try again.';
    if (error is SocketException) return 'No internet connection.';
    if (error is HttpException) return 'Unable to reach the server.';
    final msg = error.toString().toLowerCase();
    if (msg.contains('timeout')) return 'Request timed out. Please retry.';
    if (msg.contains('unauthorized') || msg.contains('401')) {
      return 'You need to sign in again.';
    }
    if (msg.contains('forbidden') || msg.contains('403')) {
      return 'You don’t have permission to do that.';
    }
    if (msg.contains('404')) return 'Not found.';
    if (msg.contains('500') || msg.contains('502') || msg.contains('503') || msg.contains('504')) {
      return 'Server issue. Please try again later.';
    }
    return fb;
  }

  Future<void> _load() async {
    setState(() {
      _loading = true;
      _err = null;
    });
    try {
      final list = await ApiService.adminPending(
        q: _search.text.trim(),
        page: 1,
        limit: 100,
      );
      if (!mounted) return;
      setState(() {
        _items = list.cast<Map>().map((e) => e.cast<String, dynamic>()).toList();
        _loading = false;
      });
    } catch (e) {
      if (!mounted) return;
      setState(() {
        _loading = false;
        _err = _friendly(e);
        // Commented out to avoid exposing internals to users.
        // final String techDetail = e.toString();
      });
    }
  }

  Future<void> _decide(Map<String, dynamic> u, bool approve) async {
    final userId = (u['id'] ?? '').toString();
    if (userId.isEmpty) return;

    setState(() => _busyUserId = userId);

    // Optimistic remove for snappy UI.
    final idx = _items.indexOf(u);
    if (idx < 0) {
      setState(() => _busyUserId = null);
      return;
    }
    final removed = _items.removeAt(idx);
    setState(() {});

    try {
      final ok = approve
          ? await ApiService.adminVerify(userId)
          : await ApiService.adminReject(userId);

      if (!ok) {
        // Commented: techy sentinel detail; keep UX generic.
        // throw Exception('SERVER');
        throw Exception();
      }

      if (!mounted) return;
      ScaffoldMessenger.of(context).clearSnackBars();
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(approve ? 'Approved' : 'Rejected'),
          behavior: SnackBarBehavior.floating,
          duration: const Duration(seconds: 2),
        ),
      );
    } catch (e) {
      if (mounted) {
        // Rollback on failure.
        setState(() => _items.insert(idx, removed));
        ScaffoldMessenger.of(context).clearSnackBars();
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Text(_friendly(e)),
            behavior: SnackBarBehavior.floating,
            duration: const Duration(seconds: 3),
          ),
        );
      }
    } finally {
      if (mounted) setState(() => _busyUserId = null);
    }
  }

  void _openProfile(String userId) {
    Navigator.of(context).pushNamed('/user', arguments: {'id': userId});
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text('Admin • Pending approvals'),
        actions: [
          IconButton(onPressed: _load, icon: const Icon(Icons.refresh)),
        ],
      ),
      body: Column(
        children: [
          Padding(
            padding: const EdgeInsets.fromLTRB(12, 10, 12, 6),
            child: TextField(
              controller: _search,
              onSubmitted: (_) => _load(),
              decoration: const InputDecoration(
                hintText: 'Search name or @username',
                isDense: true,
                prefixIcon: Icon(Icons.search),
                border: OutlineInputBorder(),
                contentPadding: EdgeInsets.symmetric(horizontal: 12, vertical: 10),
              ),
            ),
          ),
          Expanded(
            child: _loading
                ? const Center(child: CircularProgressIndicator())
                : _err != null
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
                    : _items.isEmpty
                        ? const Center(child: Text('No pending applications'))
                        : ListView.separated(
                            padding: const EdgeInsets.fromLTRB(12, 4, 12, 12),
                            itemCount: _items.length,
                            separatorBuilder: (_, __) => const SizedBox(height: 10),
                            itemBuilder: (_, i) => _tile(_items[i]),
                          ),
          ),
        ],
      ),
    );
  }

  // ---------- UI bits ----------

  Widget _statusPill(String text, {Color? bg}) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
      decoration: BoxDecoration(
        color: bg ?? const Color(0xFFF4F5F7),
        borderRadius: BorderRadius.circular(999),
        border: Border.all(color: Colors.black12),
      ),
      child: Text(
        text,
        style: const TextStyle(fontSize: 11, fontWeight: FontWeight.w600),
      ),
    );
  }

  ButtonStyle get _stadiumOutline => OutlinedButton.styleFrom(
        minimumSize: const Size(104, 36),
        shape: const StadiumBorder(),
        side: const BorderSide(color: Colors.black, width: 1.3),
        foregroundColor: Colors.black,
      );

  ButtonStyle get _stadiumFilled => FilledButton.styleFrom(
        minimumSize: const Size(104, 36),
        shape: const StadiumBorder(),
        backgroundColor: Colors.black,
        foregroundColor: Colors.white,
      );

  Widget _tile(Map<String, dynamic> u) {
    final id = (u['id'] ?? '').toString();
    final name = (u['display_name'] ?? u['name'] ?? '').toString();
    final username = (u['username'] ?? '').toString();
    final avatar = (u['avatar_url'] ?? '').toString();
    final role = (u['role'] ?? '').toString();
    final status = (u['status'] ?? '').toString();

    final busy = _busyUserId == id;

    return Opacity(
      opacity: busy ? 0.6 : 1,
      child: IgnorePointer(
        ignoring: busy,
        child: Container(
          padding: const EdgeInsets.all(12),
          decoration: BoxDecoration(
            color: Colors.white,
            border: Border.all(color: Colors.black12),
            borderRadius: BorderRadius.circular(16),
          ),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              // Row 1
              Row(
                crossAxisAlignment: CrossAxisAlignment.center,
                children: [
                  CircleAvatar(
                    radius: 20,
                    backgroundImage: avatar.isNotEmpty ? NetworkImage(avatar) : null,
                    child: avatar.isEmpty ? const Icon(Icons.person) : null,
                  ),
                  const SizedBox(width: 10),
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(name, style: const TextStyle(fontWeight: FontWeight.w800)),
                        if (username.isNotEmpty)
                          Text('@$username', style: const TextStyle(color: Colors.black54)),
                      ],
                    ),
                  ),
                  TextButton.icon(
                    onPressed: () => _openProfile(id),
                    style: TextButton.styleFrom(foregroundColor: Colors.black),
                    icon: const Icon(Icons.open_in_new, size: 18),
                    label: const Text('View'),
                  ),
                ],
              ),
              const SizedBox(height: 10),
              // Row 2
              LayoutBuilder(
                builder: (ctx, c) {
                  final narrow = c.maxWidth < 360;
                  final chips = Wrap(
                    spacing: 6,
                    runSpacing: 6,
                    children: [
                      _statusPill('role: $role'),
                      _statusPill('status: $status', bg: const Color(0xFFFFF4CC)),
                    ],
                  );

                  final actions = Wrap(
                    spacing: 10,
                    runSpacing: 8,
                    children: [
                      SizedBox(
                        height: 36,
                        child: OutlinedButton(
                          style: _stadiumOutline,
                          onPressed: () => _decide(u, false),
                          child: const Text('Reject'),
                        ),
                      ),
                      SizedBox(
                        height: 36,
                        child: FilledButton(
                          style: _stadiumFilled,
                          onPressed: () => _decide(u, true),
                          child: const Text('Approve'),
                        ),
                      ),
                    ],
                  );

                  if (narrow) {
                    return Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        chips,
                        const SizedBox(height: 10),
                        Align(alignment: Alignment.centerRight, child: actions),
                      ],
                    );
                  }

                  return Row(
                    crossAxisAlignment: CrossAxisAlignment.center,
                    children: [
                      Expanded(child: chips),
                      actions,
                    ],
                  );
                },
              ),
            ],
          ),
        ),
      ),
    );
  }
}
