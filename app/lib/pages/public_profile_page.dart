import 'dart:io';
import 'package:flutter/material.dart';
import '../services/api.dart';
import '../widgets/follow_button.dart';
import 'follow_list_page.dart';
import 'chat_page.dart';

class PublicProfilePage extends StatefulWidget {
  final String userId;
  const PublicProfilePage({super.key, required this.userId});

  @override
  State<PublicProfilePage> createState() => _PublicProfilePageState();
}

class _PublicProfilePageState extends State<PublicProfilePage> {
  Map<String, dynamic> _data = {};
  bool _loading = true;
  String? _err;

  int _spotScore = 0;
  bool _spotVoted = false;
  bool _voting = false;

  // User-safe error mapping (why: avoid exposing internal/dev details)
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

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() {
      _loading = true;
      _err = null;
    });

    try {
      final res = await ApiService.get(
        'profile_overview.php?user_id=${Uri.encodeQueryComponent(widget.userId)}',
      );
      if (!mounted) return;

      if (res['user'] != null) {
        _data = res;

        // Spotlight info best-effort; ignore failures
        try {
          final st = await ApiService.get(
            'spotlight_status.php?user_id=${Uri.encodeQueryComponent(widget.userId)}&days=30',
          );
          if (mounted && st['ok'] == true) {
            setState(() {
              _spotScore = (st['score'] as int?) ?? 0;
              _spotVoted = (st['voted'] == true);
            });
          }
        } catch (_) {}

        setState(() => _loading = false);
      } else {
        setState(() {
          _loading = false;
          _err = _friendly(Exception(res['error'] ?? 'load_failed'), fallback: 'Couldn’t load profile.');
        });
      }
    } catch (e) {
      if (!mounted) return;
      setState(() {
        _loading = false;
        _err = _friendly(e, fallback: 'Couldn’t load profile.');
      });
    }
  }

  Widget _pill(String text, {Color? color}) => Container(
        padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 5),
        decoration: BoxDecoration(
          color: color ?? Colors.black12,
          borderRadius: BorderRadius.circular(999),
        ),
        child: Text(text, style: const TextStyle(fontSize: 12, fontWeight: FontWeight.w600)),
      );

  Future<void> _toggleSpotlight(String targetId, {required bool isMe}) async {
    if (isMe) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('You can’t vote for yourself.')),
      );
      return;
    }
    if (_voting) return;

    final prevV = _spotVoted;
    final prevS = _spotScore;

    setState(() {
      _voting = true;
      _spotVoted = !prevV;
      _spotScore = prevV ? (prevS - 1).clamp(0, 1 << 30) : prevS + 1;
    });

    try {
      final res = await ApiService.postForm('spotlight_vote.php', {
        'target_user_id': targetId,
        'days': '30',
      });
      if (!mounted) return;

      if (res['ok'] == true) {
        setState(() {
          _spotVoted = (res['voted'] == true);
          _spotScore = (res['score'] as int?) ?? _spotScore;
          _voting = false;
        });
      } else {
        setState(() {
          _spotVoted = prevV;
          _spotScore = prevS;
          _voting = false;
        });
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(content: Text('Couldn’t update vote. Please try again.')),
        );
      }
    } catch (e) {
      if (!mounted) return;
      setState(() {
        _spotVoted = prevV;
        _spotScore = prevS;
        _voting = false;
      });
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(_friendly(e, fallback: 'Couldn’t update vote.'))),
      );
    }
  }

  Future<void> _openChat(String otherUserId) async {
    if (otherUserId.isEmpty) return;
    await Navigator.push(
      context,
      MaterialPageRoute(builder: (_) => ChatPage(otherUserId: otherUserId)),
    );
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(backgroundColor: Colors.white, foregroundColor: Colors.black, elevation: .5),
      body: _buildBody(),
    );
  }

  Widget _buildBody() {
    if (_loading) return const Center(child: CircularProgressIndicator());
    if (_err != null) {
      return Center(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            const Icon(Icons.info_outline),
            const SizedBox(height: 8),
            Text(_err!, textAlign: TextAlign.center),
            const SizedBox(height: 8),
            OutlinedButton(onPressed: _load, child: const Text('Retry')),
          ],
        ),
      );
    }

    final u = (_data['user'] as Map<String, dynamic>?) ?? {};
    final id = (u['id'] ?? '').toString();
    final name = (u['display_name'] ?? u['name'] ?? '').toString();
    final username = (u['username'] ?? '').toString();
    final bio = (u['bio'] ?? '').toString().trim();
    final avatar = (u['avatar_url'] ?? '').toString();
    final isMe = (u['is_me'] == true);
    final isFollowing = (u['is_following'] == true) || (u['following'] == true);

    final counts = (_data['counts'] as Map<String, dynamic>?) ?? {};
    final posts = (counts['posts'] ?? 0) as int;
    final followers = (counts['followers'] ?? 0) as int;
    final following = (counts['following'] ?? 0) as int;

    final role = (u['role'] ?? '').toString().toLowerCase();
    final status = (u['status'] ?? '').toString().toLowerCase();
    final isMentorRole = role == 'mentor';
    final isAdmin = role == 'admin';
    final isVerified = status == 'verified';

    return RefreshIndicator(
      onRefresh: _load,
      child: ListView(
        padding: EdgeInsets.zero,
        children: [
          Padding(
            padding: const EdgeInsets.fromLTRB(16, 8, 16, 12),
            child: Container(
              padding: const EdgeInsets.all(16),
              decoration: BoxDecoration(
                color: Colors.white,
                borderRadius: BorderRadius.circular(18),
                boxShadow: const [BoxShadow(color: Colors.black12, blurRadius: 10, offset: Offset(0, 6))],
              ),
              child: Row(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Container(
                    padding: const EdgeInsets.all(3),
                    decoration: BoxDecoration(
                      shape: BoxShape.circle,
                      border: Border.all(color: Colors.black, width: 1.2),
                    ),
                    child: CircleAvatar(
                      radius: 34,
                      backgroundImage: avatar.isNotEmpty ? NetworkImage(avatar) : null,
                      child: avatar.isEmpty ? const Icon(Icons.person) : null,
                    ),
                  ),
                  const SizedBox(width: 14),
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(name, style: const TextStyle(fontSize: 22, fontWeight: FontWeight.w800)),
                        if (username.isNotEmpty)
                          Padding(
                            padding: const EdgeInsets.only(top: 4),
                            child: Text('@$username', style: const TextStyle(color: Colors.black54)),
                          ),
                        if (bio.isNotEmpty)
                          Padding(
                            padding: const EdgeInsets.only(top: 8),
                            child: Text(bio, style: const TextStyle(height: 1.25)),
                          ),
                        if ((isMentorRole && isVerified) || (isAdmin && isVerified))
                          Padding(
                            padding: const EdgeInsets.only(top: 8),
                            child: _pill('Verified mentor', color: const Color(0xFFDFF6DD)),
                          )
                        else if (isMentorRole)
                          Padding(
                            padding: const EdgeInsets.only(top: 8),
                            child: _pill('Mentor'),
                          ),
                      ],
                    ),
                  ),
                  Column(
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      OutlinedButton.icon(
                        onPressed: (isMe || _voting) ? null : () => _toggleSpotlight(id, isMe: isMe),
                        icon: _voting
                            ? const SizedBox(width: 14, height: 14, child: CircularProgressIndicator(strokeWidth: 2))
                            : Icon(
                                _spotVoted ? Icons.whatshot : Icons.whatshot_outlined,
                                size: 18,
                                color: _spotVoted ? Colors.redAccent : null,
                              ),
                        label: Text('$_spotScore'),
                        style: OutlinedButton.styleFrom(
                          padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
                          minimumSize: const Size(0, 0),
                          tapTargetSize: MaterialTapTargetSize.shrinkWrap,
                        ),
                      ),
                      const SizedBox(height: 8),
                      FollowButton(
                        userId: id,
                        initialFollowing: isFollowing,
                        isMe: isMe,
                        onChanged: (_) async {
                          try {
                            final c = await ApiService.get(
                              'follow_counts.php?user_id=${Uri.encodeQueryComponent(id)}',
                            );
                            if (c['counts'] is Map && mounted) {
                              setState(() => _data['counts'] = c['counts']);
                            }
                          } catch (_) {
                            // silent; best-effort
                          }
                        },
                      ),
                      const SizedBox(height: 8),
                      if (!isMe && id.isNotEmpty)
                        FilledButton.icon(
                          onPressed: () => _openChat(id),
                          icon: const Icon(Icons.chat_bubble_outline),
                          label: const Text('Message'),
                          style: FilledButton.styleFrom(
                            padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
                            minimumSize: const Size(0, 0),
                            tapTargetSize: MaterialTapTargetSize.shrinkWrap,
                          ),
                        ),
                    ],
                  ),
                ],
              ),
            ),
          ),
          Padding(
            padding: const EdgeInsets.fromLTRB(16, 4, 16, 0),
            child: Row(
              children: [
                _Stat(label: 'Posts', value: posts.toString(), onTap: null),
                _Stat(
                  label: 'Followers',
                  value: followers.toString(),
                  onTap: () => Navigator.push(
                    context,
                    MaterialPageRoute(builder: (_) => FollowListPage(userId: id, initialType: 'followers')),
                  ),
                ),
                _Stat(
                  label: 'Following',
                  value: following.toString(),
                  onTap: () => Navigator.push(
                    context,
                    MaterialPageRoute(builder: (_) => FollowListPage(userId: id, initialType: 'following')),
                  ),
                ),
              ],
            ),
          ),
          const SizedBox(height: 12),
          const Divider(height: 1),
          const SizedBox(height: 12),
          Padding(
            padding: const EdgeInsets.symmetric(horizontal: 16),
            child: _grid(_data['posts'] as List<dynamic>?),
          ),
          const SizedBox(height: 24),
        ],
      ),
    );
  }

  Widget _grid(List<dynamic>? items) {
    final list = (items ?? const []).cast<Map<String, dynamic>>();
    if (list.isEmpty) {
      return Container(
        alignment: Alignment.centerLeft,
        height: 200,
        child: const Text('No posts yet'),
      );
    }
    return GridView.builder(
      shrinkWrap: true,
      physics: const NeverScrollableScrollPhysics(),
      itemCount: list.length,
      gridDelegate: const SliverGridDelegateWithFixedCrossAxisCount(
        crossAxisCount: 2, crossAxisSpacing: 10, mainAxisSpacing: 10,
      ),
      itemBuilder: (_, i) {
        final url = (list[i]['media_url'] ?? '').toString();
        return ClipRRect(
          borderRadius: BorderRadius.circular(12),
          child: url.isEmpty ? Container(color: Colors.black12) : Image.network(url, fit: BoxFit.cover),
        );
      },
    );
  }
}

class _Stat extends StatelessWidget {
  final String label;
  final String value;
  final VoidCallback? onTap;
  const _Stat({required this.label, required this.value, this.onTap});

  @override
  Widget build(BuildContext context) {
    final child = Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(value, style: const TextStyle(fontSize: 18, fontWeight: FontWeight.w800)),
        const SizedBox(height: 2),
        Text(label, style: const TextStyle(color: Colors.grey)),
      ],
    );
    return Expanded(
      child: InkWell(
        onTap: onTap,
        borderRadius: BorderRadius.circular(12),
        child: Padding(
          padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 10),
          child: child,
        ),
      ),
    );
  }
}