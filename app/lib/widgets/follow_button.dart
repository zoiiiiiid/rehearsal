import 'package:flutter/material.dart';
import '../services/api.dart';

class FollowButton extends StatefulWidget {
  final String userId;
  final bool initialFollowing;
  final bool isMe;
  final ValueChanged<bool>? onChanged;
  final bool small;

  const FollowButton({
    super.key,
    required this.userId,
    required this.initialFollowing,
    this.isMe = false,
    this.onChanged,
    this.small = true,
  });

  @override
  State<FollowButton> createState() => _FollowButtonState();
}

class _FollowButtonState extends State<FollowButton> {
  late bool _following;
  bool _busy = false;

  @override
  void initState() {
    super.initState();
    _following = widget.initialFollowing;
  }

  Future<void> _toggle() async {
    if (_busy || widget.isMe) return;
    setState(() => _busy = true);

    final optimisticNext = !_following;
    setState(() => _following = optimisticNext); // optimistic

    final res = await ApiService.postForm('follow_toggle.php', {
      'target_id': widget.userId,
    });

    if (!mounted) return;

    if (res['ok'] == true) {
      widget.onChanged?.call(_following);
    } else {
      // revert and show why
      setState(() => _following = !optimisticNext);
      final msg = (res['error'] ?? 'ERROR').toString();
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(msg)));
    }
    setState(() => _busy = false);
  }

  @override
  Widget build(BuildContext context) {
    if (widget.isMe) return const SizedBox.shrink(); // don't show for self

    final label = _following ? 'Following' : 'Follow';
    final bg = _following ? Colors.black : Colors.white;
    final fg = _following ? Colors.white : Colors.black;

    return SizedBox(
      height: widget.small ? 32 : 36,
      child: OutlinedButton(
        onPressed: _busy ? null : _toggle,
        style: OutlinedButton.styleFrom(
          backgroundColor: bg,
          foregroundColor: fg,
          side: const BorderSide(color: Colors.black, width: 1),
          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(20)),
          padding: const EdgeInsets.symmetric(horizontal: 14),
        ),
        child: _busy
            ? const SizedBox(
                width: 16, height: 16, child: CircularProgressIndicator(strokeWidth: 2))
            : Text(label),
      ),
    );
  }
}