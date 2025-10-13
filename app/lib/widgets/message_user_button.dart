// lib/widgets/message_user_button.dart
import 'package:flutter/material.dart';
import '../pages/chat_page.dart';

/// Compact button to start a DM with [otherUserId].
/// Why: one place to reuse on profile/search/results.
class MessageUserButton extends StatelessWidget {
  final String otherUserId;
  final String? otherDisplayName;
  final bool dense; // true => small icon button for app bars

  const MessageUserButton({
    super.key,
    required this.otherUserId,
    this.otherDisplayName,
    this.dense = false,
  });

  void _openChat(BuildContext context) async {
    if (otherUserId.isEmpty) {
      ScaffoldMessenger.of(context)
          .showSnackBar(const SnackBar(content: Text('Cannot message this user')));
      return;
    }
    await Navigator.push(
      context,
      MaterialPageRoute(
        builder: (_) => ChatPage(otherUserId: otherUserId),
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    if (dense) {
      return IconButton(
        tooltip: 'Message',
        icon: const Icon(Icons.chat_bubble_outline),
        onPressed: () => _openChat(context),
      );
    }
    return FilledButton.icon(
      onPressed: () => _openChat(context),
      icon: const Icon(Icons.chat_bubble_outline),
      label: const Text('Message'),
    );
  }
}