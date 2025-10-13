// lib/pages/chat_page.dart
import 'dart:async';
import 'dart:io';
import 'package:file_picker/file_picker.dart';
import 'package:flutter/foundation.dart' show kIsWeb;
import 'package:flutter/material.dart';
import '../services/api.dart';
import '../widgets/conversation_app_bar.dart';

class ChatPage extends StatefulWidget {
  const ChatPage({
    super.key,
    required this.otherUserId,
    this.conversationId,
    this.otherDisplayName,
    this.otherUsername,
    this.otherAvatarUrl,
  });

  final String otherUserId;
  final int? conversationId;
  final String? otherDisplayName;
  final String? otherUsername;
  final String? otherAvatarUrl;

  @override
  State<ChatPage> createState() => _ChatPageState();
}

class _ChatPageState extends State<ChatPage> {
  final TextEditingController _ctl = TextEditingController();
  final ScrollController _scroll = ScrollController();

  final List<Map<String, dynamic>> _items = [];
  Timer? _poll;
  int? _convId;
  int? _lastId;
  bool _sending = false;

  @override
  void initState() {
    super.initState();
    _convId = widget.conversationId;
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (_convId != null) _initialLoad();
    });
    _poll = Timer.periodic(const Duration(seconds: 3), (_) => _tick());
  }

  @override
  void dispose() {
    _poll?.cancel();
    _ctl.dispose();
    _scroll.dispose();
    super.dispose();
  }

  // User-safe error mapping (why: avoid dev-facing messages).
  String _friendly(Object error, {String? fallback}) {
    final fb = fallback ?? 'Something went wrong. Please try again.';
    if (error is SocketException) return 'No internet connection.';
    if (error is HttpException) return 'Unable to reach the server.';
    final msg = error.toString().toLowerCase();
    if (msg.contains('timeout')) return 'Request timed out. Please retry.';
    if (msg.contains('unauthorized') || msg.contains('401')) return 'Please sign in again.';
    if (msg.contains('forbidden') || msg.contains('403')) return 'You don’t have permission to do that.';
    if (msg.contains('too large') || msg.contains('payload') || msg.contains('413')) {
      return 'File is too large.';
    }
    if (msg.contains('unsupported') || msg.contains('mimetype') || msg.contains('extension')) {
      return 'Unsupported file type.';
    }
    if (msg.contains('server') || msg.contains('500') || msg.contains('502') || msg.contains('503') || msg.contains('504')) {
      return 'Server issue. Please try again later.';
    }
    return fb;
  }

  // ---------------- networking ----------------

  Future<void> _initialLoad() async {
    final id = _convId;
    if (id == null) return;

    final list = await ApiService.listMessages(id);
    if (!mounted) return;

    final parsed = _castList(list);
    if (parsed.isEmpty) return;

    setState(() {
      _items
        ..clear()
        ..addAll(parsed);
      _lastId = _parseInt(_items.last['id']);
    });

    _scrollToEnd();

    if (_lastId != null) {
      ApiService.markRead(conversationId: id, lastId: _lastId!);
    }
  }

  Future<void> _tick() async {
    final id = _convId;
    if (id == null) return;

    final list = await ApiService.listMessages(id, sinceId: _lastId);
    if (!mounted) return;

    final newMsgs = _castList(list);
    if (newMsgs.isEmpty) return;

    // Remove local pending echoes for the same content (why: avoid duplicates).
    for (final m in newMsgs) {
      if (_isMine(m)) {
        final content = (m['content'] ?? '').toString();
        final idx = _items.indexWhere(
          (x) => x['__local_pending'] == true && _isMine(x) && (x['content'] ?? '').toString() == content,
        );
        if (idx != -1) _items.removeAt(idx);
      }
    }

    setState(() {
      _items.addAll(newMsgs);
      _lastId = _parseInt(_items.last['id']);
    });

    _scrollToEnd();

    if (_lastId != null) {
      ApiService.markRead(conversationId: id, lastId: _lastId!);
    }
  }

  // ---------------- send: text ----------------

  Future<void> _send() async {
    final text = _ctl.text.trim();
    if (text.isEmpty || _sending) return;

    _ctl.clear();

    final nowIso = DateTime.now().toUtc().toIso8601String();
    final local = <String, dynamic>{
      'id': -DateTime.now().millisecondsSinceEpoch,
      'sender_id': '__me',
      '__local_pending': true,
      'type': 'text',
      'content': text,
      'created_at': nowIso,
    };

    setState(() => _items.add(local));
    _scrollToEnd();

    setState(() => _sending = true);
    try {
      final cid = await ApiService.sendTextMessage(
        receiverId: widget.otherUserId,
        content: text,
      );
      if (!mounted) return;

      if (_convId != cid) {
        setState(() => _convId = cid);
        // If conversation just created, load history so receipts show.
        unawaited(_initialLoad());
      } else {
        unawaited(_tick());
      }
    } finally {
      if (mounted) setState(() => _sending = false);
    }
  }

  // ---------------- send: media (image/video) ----------------

  Future<void> _sendMedia() async {
    if (_sending) return;

    final pick = await FilePicker.platform.pickFiles(
      type: FileType.custom,
      allowedExtensions: ['jpg', 'jpeg', 'png', 'webp', 'gif', 'mp4'],
      withData: true,
      allowMultiple: false,
    );
    if (pick == null || pick.files.isEmpty) return;
    final f = pick.files.first;

    final nowIso = DateTime.now().toUtc().toIso8601String();
    final tempId = -DateTime.now().millisecondsSinceEpoch;
    final isVideo = (f.extension ?? '').toLowerCase() == 'mp4';
    final local = <String, dynamic>{
      'id': tempId,
      'sender_id': '__me',
      '__local_pending': true,
      'type': isVideo ? 'video' : 'image',
      'content': '[media]',
      'media_url': '',
      'created_at': nowIso,
    };
    setState(() {
      _items.add(local);
      _sending = true;
    });
    _scrollToEnd();

    try {
      final fields = <String, String>{};
      if (_convId != null) {
        fields['conversation_id'] = '${_convId!}';
      } else {
        fields['receiver_id'] = widget.otherUserId;
      }

      final r = await ApiService.uploadMultipart(
        'messages_send_media.php',
        fieldName: 'file',
        filename: f.name,
        bytes: f.bytes,
        filePath: kIsWeb ? null : f.path,
        fields: fields,
      );

      if (!mounted) return;

      if (r['ok'] == true) {
        final cid = (r['conversation_id'] as num?)?.toInt();
        if (cid != null && _convId != cid) {
          setState(() => _convId = cid);
          unawaited(_initialLoad());
        } else {
          unawaited(_tick());
        }
      } else {
        // Keep message private; avoid leaking backend codes.
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text(_friendly(Exception('upload_failed'), fallback: 'Couldn’t send. Please try again.'))),
        );
        final idx = _items.indexWhere((m) => m['id'] == tempId);
        if (idx != -1) setState(() => _items.removeAt(idx));
      }
    } catch (e) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(_friendly(e, fallback: 'Couldn’t send. Please try again.'))),
      );
      final idx = _items.indexWhere((m) => m['id'] == tempId);
      if (idx != -1) setState(() => _items.removeAt(idx));
    } finally {
      if (mounted) setState(() => _sending = false);
    }
  }

  // ---------------- helpers ----------------

  List<Map<String, dynamic>> _castList(dynamic v) {
    return (v as List? ?? const [])
        .cast<Map>()
        .map((e) => e.cast<String, dynamic>())
        .toList();
  }

  int? _parseInt(dynamic v) {
    if (v is int) return v;
    if (v is num) return v.toInt();
    final s = v?.toString();
    return (s == null || s.isEmpty) ? null : int.tryParse(s);
  }

  bool _isMine(Map<String, dynamic> m) {
    final sender = (m['sender_id'] ?? '').toString();
    // Why: only peer id is known, so "not peer" => mine.
    return sender != widget.otherUserId;
  }

  String _fmtTime(dynamic iso) {
    final s = iso?.toString();
    if (s == null || s.isEmpty) return '';
    final dt = DateTime.tryParse(s)?.toLocal();
    if (dt == null) return '';
    final h = dt.hour;
    final m = dt.minute;
    String two(int n) => n < 10 ? '0$n' : '$n';
    return '${two(h)}:${two(m)}';
  }

  /// pending: local unsent; delivered: has delivered_at; read: has read_at.
  ({bool pending, bool delivered, bool read}) _status(Map<String, dynamic> m) {
    final pending = m['__local_pending'] == true;
    final read = (m['read'] == true) ||
        (m['is_read'] == true) ||
        (m['is_read'] == 1) ||
        (m['read_at'] != null);
    final delivered = read ||
        (m['delivered'] == true) ||
        (m['is_delivered'] == true) ||
        (m['delivered_at'] != null) ||
        !pending;
    return (pending: pending, delivered: delivered, read: read);
  }

  void _scrollToEnd() {
    if (!_scroll.hasClients) return;
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (_scroll.hasClients) {
        _scroll.jumpTo(_scroll.position.maxScrollExtent);
      }
    });
  }

  void unawaited(Future<void> f) {}

  // ---------------- UI ----------------

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: ConversationAppBar(
        userId: widget.otherUserId,
        otherDisplayName: widget.otherDisplayName,
        otherUsername: widget.otherUsername,
        otherAvatarUrl: widget.otherAvatarUrl,
      ),
      body: Column(
        children: [
          Expanded(
            child: ListView.builder(
              controller: _scroll,
              padding: const EdgeInsets.fromLTRB(12, 10, 12, 10),
              itemCount: _items.length,
              itemBuilder: (_, i) => _bubble(_items[i]),
            ),
          ),
          _inputBar(),
        ],
      ),
    );
  }

  Widget _bubble(Map<String, dynamic> m) {
    final mine = _isMine(m);
    final text = (m['content'] ?? '').toString();
    final media = (m['media_url'] ?? '').toString();
    final typ = (m['type'] ?? '').toString().toLowerCase();
    final time = _fmtTime(m['created_at'] ?? m['time'] ?? m['sent_at']);

    final otherBg = const Color(0xFFF4F5F7);
    final myBg = Colors.black;
    final radius = BorderRadius.circular(16);

    Widget inner;

    final hasMedia = media.isNotEmpty || typ == 'image' || typ == 'video';
    if (hasMedia) {
      final isVideo = media.toLowerCase().endsWith('.mp4') || typ == 'video';
      final uploading = m['__local_pending'] == true && media.isEmpty;

      Widget mediaWidget;
      if (uploading) {
        mediaWidget = Container(
          width: 220,
          height: 140,
          alignment: Alignment.center,
          decoration: BoxDecoration(
            color: mine ? Colors.white12 : Colors.black12,
            borderRadius: BorderRadius.circular(10),
          ),
          child: const SizedBox(width: 22, height: 22, child: CircularProgressIndicator(strokeWidth: 2)),
        );
      } else if (isVideo) {
        mediaWidget = Container(
          width: 220,
          height: 140,
          alignment: Alignment.center,
          decoration: BoxDecoration(
            color: Colors.black12,
            borderRadius: BorderRadius.circular(10),
          ),
          child: const Icon(Icons.play_circle_outline, size: 42),
        );
      } else {
        mediaWidget = ClipRRect(
          borderRadius: BorderRadius.circular(10),
          child: Image.network(media, width: 220, fit: BoxFit.cover),
        );
      }

      inner = Column(
        crossAxisAlignment: CrossAxisAlignment.end,
        mainAxisSize: MainAxisSize.min,
        children: [
          mediaWidget,
          if (text.isNotEmpty) const SizedBox(height: 6),
          if (text.isNotEmpty)
            Text(
              text,
              style: TextStyle(color: mine ? Colors.white : Colors.black87, height: 1.25),
            ),
          const SizedBox(height: 4),
          Row(
            mainAxisSize: MainAxisSize.min,
            children: [
              Text(
                time,
                style: TextStyle(color: mine ? Colors.white70 : Colors.black45, fontSize: 11),
              ),
              if (mine) ...[
                const SizedBox(width: 6),
                _statusIcon(m),
              ],
            ],
          ),
        ],
      );
    } else {
      inner = Column(
        crossAxisAlignment: CrossAxisAlignment.end,
        mainAxisSize: MainAxisSize.min,
        children: [
          Text(
            text,
            style: TextStyle(color: mine ? Colors.white : Colors.black87, height: 1.25),
          ),
          const SizedBox(height: 4),
          Row(
            mainAxisSize: MainAxisSize.min,
            children: [
              Text(
                time,
                style: TextStyle(color: mine ? Colors.white70 : Colors.black45, fontSize: 11),
              ),
              if (mine) ...[
                const SizedBox(width: 6),
                _statusIcon(m),
              ],
            ],
          ),
        ],
      );
    }

    return Align(
      alignment: mine ? Alignment.centerRight : Alignment.centerLeft,
      child: ConstrainedBox(
        constraints: const BoxConstraints(maxWidth: 320),
        child: Container(
          padding: const EdgeInsets.fromLTRB(12, 8, 12, 6),
          margin: const EdgeInsets.symmetric(vertical: 6),
          decoration: BoxDecoration(
            color: mine ? myBg : otherBg,
            borderRadius: radius,
            border: mine ? null : Border.all(color: Colors.black12),
            boxShadow: mine
                ? [
                    BoxShadow(
                      color: Colors.black.withOpacity(.08),
                      blurRadius: 8,
                      offset: const Offset(0, 3),
                    )
                  ]
                : null,
          ),
          child: inner,
        ),
      ),
    );
  }

  Widget _statusIcon(Map<String, dynamic> m) {
    final st = _status(m);
    if (st.pending) {
      return const Icon(Icons.access_time, size: 14, color: Colors.white70);
    }
    if (st.read) {
      return const Icon(Icons.done_all, size: 16, color: Colors.white);
    }
    if (st.delivered) {
      return const Icon(Icons.done_all, size: 16, color: Colors.white70);
    }
    return const Icon(Icons.done, size: 14, color: Colors.white70);
  }

  Widget _inputBar() {
    return SafeArea(
      top: false,
      child: Padding(
        padding: const EdgeInsets.fromLTRB(12, 8, 12, 10),
        child: Row(
          children: [
            Material(
              color: Colors.black,
              shape: const CircleBorder(),
              child: IconButton(
                onPressed: _sending ? null : _sendMedia,
                icon: const Icon(Icons.attach_file, color: Colors.white),
              ),
            ),
            const SizedBox(width: 8),
            Expanded(
              child: TextField(
                controller: _ctl,
                minLines: 1,
                maxLines: 5,
                onSubmitted: (_) => _send(),
                decoration: InputDecoration(
                  hintText: 'Message…',
                  isDense: true,
                  filled: true,
                  fillColor: Colors.grey.shade100,
                  contentPadding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
                  border: OutlineInputBorder(
                    borderRadius: BorderRadius.circular(999),
                    borderSide: const BorderSide(color: Colors.black12),
                  ),
                  enabledBorder: OutlineInputBorder(
                    borderRadius: BorderRadius.circular(999),
                    borderSide: const BorderSide(color: Colors.black12),
                  ),
                  focusedBorder: OutlineInputBorder(
                    borderRadius: BorderRadius.circular(999),
                    borderSide: const BorderSide(color: Colors.black),
                  ),
                ),
              ),
            ),
            const SizedBox(width: 8),
            Material(
              color: Colors.black,
              shape: const CircleBorder(),
              child: IconButton(
                onPressed: _sending ? null : _send,
                icon: _sending
                    ? const SizedBox(
                        width: 18,
                        height: 18,
                        child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white),
                      )
                    : const Icon(Icons.send, color: Colors.white),
              ),
            ),
          ],
        ),
      ),
    );
  }
}
