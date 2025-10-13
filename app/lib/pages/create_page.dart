// lib/pages/create_page.dart
import 'dart:typed_data';
import 'package:file_picker/file_picker.dart';
import 'package:flutter/foundation.dart' show kIsWeb;
import 'package:flutter/material.dart';
import '../services/api.dart';
import 'workshop_page.dart';

const double kBrandTop = 32.0;

const Map<String, String> _kSkillLabels = {
  'dj': 'DJ',
  'singer': 'Singer',
  'guitarist': 'Guitarist',
  'drummer': 'Drummer',
  'bassist': 'Bassist',
  'keyboardist': 'Keyboardist',
  'dancer': 'Dancer',
  'other': 'Other',
};

class CreatePage extends StatefulWidget {
  const CreatePage({super.key});
  @override
  State<CreatePage> createState() => _CreatePageState();
}

class _CreatePageState extends State<CreatePage> {
  final _caption = TextEditingController();
  PlatformFile? _picked;
  bool _uploading = false;

  String _role = 'artist';
  bool _loadingRole = true;

  String? _skill;

  @override
  void initState() {
    super.initState();
    _loadRole();
  }

  @override
  void dispose() {
    _caption.dispose();
    super.dispose();
  }

  // User-safe error mapping (why: no dev/internal messages in UI).
  String _friendly(Object error, {String? fallback}) {
    final fb = fallback ?? 'Something went wrong. Please try again.';
    final msg = error.toString().toLowerCase();
    if (msg.contains('timeout')) return 'Request timed out. Please retry.';
    if (msg.contains('network') || msg.contains('socket') || msg.contains('connection')) {
      return 'No internet connection.';
    }
    if (msg.contains('unauthorized') || msg.contains('401')) return 'Please sign in again.';
    if (msg.contains('forbidden') || msg.contains('403')) return 'You don’t have permission to do that.';
    if (msg.contains('413') || msg.contains('payload') || msg.contains('too large')) return 'File is too large.';
    if (msg.contains('unsupported') || msg.contains('mimetype') || msg.contains('extension')) {
      return 'Unsupported file type.';
    }
    if (msg.contains('server') || msg.contains('500') || msg.contains('502') || msg.contains('503') || msg.contains('504')) {
      return 'Server issue. Please try again later.';
    }
    return fb;
  }

  Future<void> _loadRole() async {
    final r = await ApiService.get('me.php');
    if (!mounted) return;
    setState(() {
      _role = (r['user']?['role'] ?? 'artist').toString();
      _loadingRole = false;
    });
  }

  Future<void> _pick() async {
    final result = await FilePicker.platform.pickFiles(
      type: FileType.custom,
      allowedExtensions: ['jpg', 'jpeg', 'png', 'webp', 'mp4'],
      withData: true,
      allowMultiple: false,
    );
    if (result == null || result.files.isEmpty) return;
    setState(() => _picked = result.files.first);
  }

  Future<void> _clearPicked() async {
    setState(() => _picked = null);
  }

  Future<void> _post() async {
    final f = _picked;

    if (_skill == null) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Please select a skill for this post')),
      );
      return;
    }
    if (f == null) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Please select a photo or video')),
      );
      return;
    }
    if (_uploading) return;

    setState(() => _uploading = true);
    ScaffoldMessenger.of(context).showSnackBar(
      const SnackBar(content: Text('Uploading…'), duration: Duration(seconds: 1)),
    );

    final String filename = f.name;
    final Uint8List? bytes = f.bytes;
    String? filePath;
    if (!kIsWeb) filePath = f.path;

    final res = await ApiService.uploadMultipart(
      'post_create.php',
      fieldName: 'media',
      filename: filename,
      bytes: bytes,
      filePath: filePath,
      fields: {
        'caption': _caption.text.trim(),
        'skill': _skill!, // required by backend
      },
    );

    if (!mounted) return;
    setState(() => _uploading = false);

    if (res['ok'] == true) {
      _caption.clear();
      setState(() {
        _picked = null;
      });
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Posted!')),
      );
      Navigator.maybePop(context);
    } else {
      // Keep details private; show user-safe copy only.
      final userMsg = _friendly(Exception(res['error'] ?? 'post_failed'), fallback: 'Couldn’t post. Please try again.');
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(userMsg)));
    }
  }

  Widget _preview() {
    final border = BoxDecoration(
      color: const Color(0xFFF5F5F7),
      borderRadius: BorderRadius.circular(16),
      border: Border.all(color: Colors.black12),
    );

    if (_picked == null) {
      return Container(
        height: 180,
        decoration: border,
        child: const Center(child: Text('No file selected')),
      );
    }

    final name = _picked!.name;
    final lower = name.toLowerCase();
    final isVideo = lower.endsWith('.mp4');
    final sizeKb = (_picked!.size / 1024).toStringAsFixed(0);

    return Container(
      padding: const EdgeInsets.all(12),
      decoration: border,
      child: Row(
        children: [
          Icon(isVideo ? Icons.videocam : Icons.image, size: 28),
          const SizedBox(width: 10),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  name,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: const TextStyle(fontWeight: FontWeight.w700),
                ),
                const SizedBox(height: 2),
                Text(
                  '${isVideo ? 'Video' : 'Image'} • ${sizeKb}KB',
                  style: const TextStyle(color: Colors.black54, fontSize: 12),
                ),
              ],
            ),
          ),
          IconButton(
            tooltip: 'Remove',
            onPressed: _uploading ? null : _clearPicked,
            icon: const Icon(Icons.close),
          ),
        ],
      ),
    );
  }

  Widget _skillPicker() {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        const Text('Choose a skill (required)', style: TextStyle(fontWeight: FontWeight.w700)),
        const SizedBox(height: 8),
        SingleChildScrollView(
          scrollDirection: Axis.horizontal,
          child: Row(
            children: _kSkillLabels.entries.map((e) {
              final selected = _skill == e.key;
              return Padding(
                padding: const EdgeInsets.only(right: 8),
                child: ChoiceChip(
                  label: Text(e.value),
                  selected: selected,
                  onSelected: (_) => setState(() => _skill = e.key),
                  shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(20)),
                ),
              );
            }).toList(),
          ),
        ),
      ],
    );
  }

  // Web-only creation; on mobile we show a browse card instead.
  Widget _workshopEntry() {
    if (_loadingRole) {
      return const Center(
        child: SizedBox(height: 20, width: 20, child: CircularProgressIndicator(strokeWidth: 2)),
      );
    }

    if (kIsWeb) {
      final enabled = _role == 'mentor' || _role == 'admin';
      final tile = ListTile(
        leading: const Icon(Icons.live_tv),
        title: const Text('Start live workshop (web)'),
        subtitle: Text(enabled ? 'Launch Zoom-hosted session' : 'Only verified mentors/admins can host'),
        onTap: enabled
            ? () => Navigator.pushNamed(context, '/workshop_create')
            : () {
                ScaffoldMessenger.of(context).showSnackBar(
                  const SnackBar(content: Text('Only verified mentors/admins can start a live workshop')),
                );
              },
      );
      return Card(child: enabled ? tile : Opacity(opacity: 0.65, child: tile));
    }

    return Card(
      child: ListTile(
        leading: const Icon(Icons.video_collection_outlined),
        title: const Text('Browse workshops'),
        subtitle: const Text('Discover ongoing & upcoming sessions'),
        onTap: () {
          Navigator.push(context, MaterialPageRoute(builder: (_) => const WorkshopPage()));
        },
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final canPost = !_uploading && _skill != null && _picked != null;

    return Scaffold(
      body: SafeArea(
        child: ListView(
          padding: const EdgeInsets.fromLTRB(16, kBrandTop, 16, 24),
          children: [
            Row(
              children: const [
                Text('Create', style: TextStyle(fontSize: 20, fontWeight: FontWeight.w800)),
                SizedBox(width: 8),
                Icon(Icons.create_outlined, size: 18),
              ],
            ),
            const SizedBox(height: 16),
            _preview(),
            const SizedBox(height: 16),
            _skillPicker(),
            const SizedBox(height: 12),
            Row(
              children: [
                OutlinedButton.icon(
                  onPressed: _uploading ? null : _pick,
                  icon: const Icon(Icons.attach_file),
                  label: const Text('Choose file'),
                ),
                const SizedBox(width: 12),
                Expanded(
                  child: TextField(
                    controller: _caption,
                    decoration: const InputDecoration(
                      hintText: 'Write a caption…',
                      border: OutlineInputBorder(),
                      isDense: true,
                    ),
                    minLines: 1,
                    maxLines: 3,
                  ),
                ),
              ],
            ),
            const SizedBox(height: 8),
            const Text('Supported: JPG • PNG • WEBP • MP4', style: TextStyle(color: Colors.black54, fontSize: 12)),
            const SizedBox(height: 16),
            FilledButton.icon(
              onPressed: canPost ? _post : null,
              icon: _uploading
                  ? const SizedBox(width: 18, height: 18, child: CircularProgressIndicator(strokeWidth: 2))
                  : const Icon(Icons.cloud_upload),
              label: const Text('Post'),
            ),
            const SizedBox(height: 24),
            _workshopEntry(),
            const SizedBox(height: 8),
            Card(
              elevation: 0,
              color: const Color(0xFFF8F9FB),
              child: Padding(
                padding: const EdgeInsets.all(12),
                child: Row(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: const [
                    Icon(Icons.info_outline, size: 18),
                    SizedBox(width: 10),
                    Expanded(
                      child: Text(
                        'For paid workshops, message the mentor/host to confirm payment and receive the Zoom code.',
                        style: TextStyle(color: Colors.black87),
                      ),
                    ),
                  ],
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }
}
