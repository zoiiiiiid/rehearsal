// lib/widgets/conversation_app_bar.dart
import 'package:flutter/material.dart';
import '../services/api.dart';

/// App bar for a 1:1 conversation.
/// - Shows avatar, display name, and @username of [userId].
/// - Accepts optional hints to avoid a fetch on open.
/// - Falls back to fetching `profile_overview.php?user_id=...` if hints are missing.
class ConversationAppBar extends StatefulWidget implements PreferredSizeWidget {
  const ConversationAppBar({
    super.key,
    required this.userId,
    this.otherDisplayName,
    this.otherUsername,
    this.otherAvatarUrl,
  });

  final String userId;
  final String? otherDisplayName;
  final String? otherUsername;
  final String? otherAvatarUrl;

  @override
  Size get preferredSize => const Size.fromHeight(kToolbarHeight);

  @override
  State<ConversationAppBar> createState() => _ConversationAppBarState();
}

class _ConversationAppBarState extends State<ConversationAppBar> {
  String? _name;
  String? _username;
  String? _avatar;
  bool _loading = false;

  @override
  void initState() {
    super.initState();
    _name     = widget.otherDisplayName;
    _username = widget.otherUsername;
    _avatar   = widget.otherAvatarUrl;

    // Fetch only if any field is missing.
    if (_name == null || _username == null || _avatar == null) {
      _load();
    }
  }

  Future<void> _load() async {
    if (_loading) return;
    setState(() => _loading = true);
    final id = widget.userId;
    final res = await ApiService.get(
      'profile_overview.php?user_id=${Uri.encodeQueryComponent(id)}',
    );
    if (!mounted) return;

    final u = (res['user'] is Map) ? (res['user'] as Map).cast<String, dynamic>() : null;
    if (u != null) {
      setState(() {
        _name     ??= (u['display_name'] ?? u['name'] ?? '').toString();
        _username ??= (u['username'] ?? '').toString();
        _avatar   ??= (u['avatar_url'] ?? '').toString();
        _loading   = false;
      });
    } else {
      setState(() => _loading = false); // keep hints (if any)
    }
  }

  @override
  Widget build(BuildContext context) {
    final name = (_name ?? '').isEmpty ? 'Chat' : _name!;
    final username = (_username ?? '').toString();
    final avatar = (_avatar ?? '').toString();

    return AppBar(
      elevation: .5,
      backgroundColor: Colors.white,
      foregroundColor: Colors.black,
      titleSpacing: 0,
      title: Row(
        children: [
          CircleAvatar(
            radius: 16,
            backgroundImage: avatar.isNotEmpty ? NetworkImage(avatar) : null,
            child: avatar.isEmpty ? const Icon(Icons.person, size: 18) : null,
          ),
          const SizedBox(width: 10),
          Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(
                name,
                style: const TextStyle(fontWeight: FontWeight.w800, color: Colors.black),
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
              ),
              if (username.isNotEmpty)
                Text(
                  '@$username',
                  style: const TextStyle(fontSize: 12, color: Colors.black54),
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                ),
            ],
          ),
        ],
      ),
    );
  }
}
