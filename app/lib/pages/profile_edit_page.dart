// lib/pages/profile_edit_page.dart
import 'dart:async';
import 'dart:io';
import 'package:file_picker/file_picker.dart';
import 'package:flutter/foundation.dart' show kIsWeb;
import 'package:flutter/material.dart';
import '../services/api.dart';

class ProfileEditPage extends StatefulWidget {
  const ProfileEditPage({super.key});
  @override
  State<ProfileEditPage> createState() => _ProfileEditPageState();
}

class _ProfileEditPageState extends State<ProfileEditPage> {
  // Text controllers
  final _nameCtrl = TextEditingController();
  final _usernameCtrl = TextEditingController();
  final _bioCtrl = TextEditingController();
  final _otherSkillCtrl = TextEditingController();

  // Username availability
  Timer? _deb;
  bool _checking = false;
  bool? _available; // null = unknown

  // Avatar
  String _avatarUrl = '';

  // Skills (multi-select)
  final Set<String> _skills = <String>{};
  bool get _otherSelected => _skills.contains('other');

  // Loading/saving state
  bool _loading = true;
  bool _saving = false;
  String? _err;

  static const Map<String, String> _skillLabels = {
    'dj': 'DJ',
    'singer': 'Singer',
    'guitarist': 'Guitarist',
    'drummer': 'Drummer',
    'bassist': 'Bassist',
    'keyboardist': 'Keyboardist',
    'dancer': 'Dancer',
    'other': 'Other',
  };

  // User-safe error mapping (why: avoid dev/internal details in UI)
  String _friendly(Object error, {String? fallback}) {
    final fb = fallback ?? 'Something went wrong. Please try again.';
    if (error is SocketException) return 'No internet connection.';
    if (error is HttpException) return 'Unable to reach the server.';
    final msg = error.toString().toLowerCase();
    if (msg.contains('timeout')) return 'Request timed out. Please retry.';
    if (msg.contains('unauthorized') || msg.contains('401')) return 'Please sign in again.';
    if (msg.contains('forbidden') || msg.contains('403')) return 'You don’t have permission to do that.';
    if (msg.contains('413') || msg.contains('too large') || msg.contains('payload')) return 'File is too large.';
    if (msg.contains('unsupported') || msg.contains('mimetype') || msg.contains('extension')) return 'Unsupported file type.';
    if (msg.contains('server') || msg.contains('500') || msg.contains('502') || msg.contains('503') || msg.contains('504')) {
      return 'Server issue. Please try again later.';
    }
    return fb;
  }

  @override
  void initState() {
    super.initState();
    _load();
    _usernameCtrl.addListener(_debouncedCheck);
  }

  @override
  void dispose() {
    _deb?.cancel();
    _nameCtrl.dispose();
    _usernameCtrl.dispose();
    _bioCtrl.dispose();
    _otherSkillCtrl.dispose();
    super.dispose();
  }

  Future<void> _load() async {
    setState(() {
      _loading = true;
      _err = null;
    });

    try {
      final res = await ApiService.get('profile_overview.php');
      if (!mounted) return;

      if (res['user'] is Map) {
        final u = (res['user'] as Map).cast<String, dynamic>();
        _nameCtrl.text = (u['display_name'] ?? u['name'] ?? '').toString();
        _usernameCtrl.text = (u['username'] ?? '').toString();
        _bioCtrl.text = (u['bio'] ?? '').toString();
        _avatarUrl = (u['avatar_url'] ?? '').toString();

        _skills.clear();
        final dynSkills = res['skills'] ?? u['skills'];
        if (dynSkills is List) {
          for (final s in dynSkills) {
            if (s is String) {
              _skills.add(s.toLowerCase());
            } else if (s is Map) {
              final code = (s['key'] ?? s['code'] ?? s['skill'] ?? '').toString().toLowerCase();
              if (code.isNotEmpty) _skills.add(code);
              if (code == 'other' && (s['label'] ?? s['other'] ?? s['other_label']) != null) {
                _otherSkillCtrl.text = (s['label'] ?? s['other'] ?? s['other_label']).toString();
              }
            }
          }
        }

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

  void _debouncedCheck() {
    _deb?.cancel();
    _available = null;
    final value = _usernameCtrl.text.trim();
    if (value.isEmpty) {
      setState(() {});
      return;
    }
    _deb = Timer(const Duration(milliseconds: 350), () async {
      setState(() => _checking = true);
      try {
        final res = await ApiService.get('username_check.php?u=${Uri.encodeQueryComponent(value)}');
        if (!mounted) return;
        _checking = false;
        _available = (res['available'] == true);
        setState(() {});
      } catch (_) {
        if (!mounted) return;
        _checking = false;
        _available = null; // unknown
        setState(() {});
      }
    });
  }

  Future<void> _pickAvatar() async {
    final result = await FilePicker.platform.pickFiles(
      type: FileType.image,
      withData: true, // web & mobile
      allowMultiple: false,
    );
    if (result == null || result.files.isEmpty) return;

    final f = result.files.first;
    final messenger = ScaffoldMessenger.of(context)..hideCurrentSnackBar();
    messenger.showSnackBar(const SnackBar(content: Text('Uploading avatar…')));

    Map<String, dynamic> up;

    try {
      if (kIsWeb) {
        if (f.bytes == null) {
          messenger
            ..hideCurrentSnackBar()
            ..showSnackBar(const SnackBar(content: Text('Couldn’t read the selected file.')));
          return;
        }
        up = await ApiService.uploadMultipart(
          'avatar_upload.php',
          fieldName: 'avatar',
          filename: f.name.isNotEmpty ? f.name : 'avatar.jpg',
          bytes: f.bytes,
        );
      } else {
        if (f.path != null) {
          up = await ApiService.uploadMultipart(
            'avatar_upload.php',
            fieldName: 'avatar',
            filename: f.name.isNotEmpty ? f.name : 'avatar.jpg',
            filePath: f.path,
          );
        } else if (f.bytes != null) {
          up = await ApiService.uploadMultipart(
            'avatar_upload.php',
            fieldName: 'avatar',
            filename: f.name.isNotEmpty ? f.name : 'avatar.jpg',
            bytes: f.bytes,
          );
        } else {
          messenger
            ..hideCurrentSnackBar()
            ..showSnackBar(const SnackBar(content: Text('Couldn’t read the selected file.')));
          return;
        }
      }
    } catch (e) {
      messenger
        ..hideCurrentSnackBar()
        ..showSnackBar(SnackBar(content: Text(_friendly(e, fallback: 'Couldn’t upload avatar.'))));
      return;
    }

    if (!mounted) return;
    messenger.hideCurrentSnackBar();

    if (up['ok'] == true && (up['url'] ?? up['avatar_url']) != null) {
      final url = (up['url'] ?? up['avatar_url']).toString();
      setState(() => _avatarUrl = url);
      messenger.showSnackBar(const SnackBar(content: Text('Avatar updated')));
    } else {
      messenger.showSnackBar(const SnackBar(content: Text('Couldn’t upload avatar. Please try again.')));
    }
  }

  Future<void> _save() async {
    final name = _nameCtrl.text.trim();
    var handle = _usernameCtrl.text.trim();
    final bio = _bioCtrl.text.trim();

    if (name.isEmpty) {
      ScaffoldMessenger.of(context).showSnackBar(const SnackBar(content: Text('Name required')));
      return;
    }
    if (handle.startsWith('@')) handle = handle.substring(1);
    if (handle.isNotEmpty && !_isValidHandle(handle)) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Username must be 3–20 chars (a–z, 0–9, _)')),
      );
      return;
    }
    if (_available == false) {
      ScaffoldMessenger.of(context).showSnackBar(const SnackBar(content: Text('Username already taken')));
      return;
    }

    setState(() => _saving = true);

    final body = <String, dynamic>{
      'name': name,
      'username': handle,
      'bio': bio,
    };
    if (_skills.isNotEmpty) {
      body['skills'] = _skills.toList();
      if (_otherSelected) {
        final otherLabel = _otherSkillCtrl.text.trim();
        if (otherLabel.isNotEmpty) body['other_skill'] = otherLabel;
      }
    }

    try {
      final res = await ApiService.post('profile_update.php', body);
      if (!mounted) return;
      setState(() => _saving = false);

      if (res['ok'] == true) {
        Navigator.pop(context, true);
      } else {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(content: Text('Couldn’t save changes. Please try again.')),
        );
      }
    } catch (e) {
      if (!mounted) return;
      setState(() => _saving = false);
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(_friendly(e, fallback: 'Couldn’t save changes.'))),
      );
    }
  }

  bool _isValidHandle(String s) => RegExp(r'^[a-z0-9_]{3,20}$').hasMatch(s);

  @override
  Widget build(BuildContext context) {
    if (_loading) return const Scaffold(body: Center(child: CircularProgressIndicator()));
    if (_err != null) {
      return Scaffold(
        appBar: AppBar(title: const Text('Edit profile')),
        body: Center(
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
        ),
      );
    }

    return Scaffold(
      appBar: AppBar(
        title: const Text('Edit profile'),
        actions: [
          TextButton(
            onPressed: _saving ? null : _save,
            child: _saving
                ? const SizedBox(width: 18, height: 18, child: CircularProgressIndicator(strokeWidth: 2))
                : const Text('Save'),
          ),
        ],
      ),
      body: ListView(
        padding: const EdgeInsets.fromLTRB(16, 16, 16, 24),
        children: [
          Row(
            children: [
              CircleAvatar(
                radius: 34,
                backgroundImage: _avatarUrl.isNotEmpty ? NetworkImage(_avatarUrl) : null,
                child: _avatarUrl.isEmpty ? const Icon(Icons.person) : null,
              ),
              const SizedBox(width: 12),
              OutlinedButton.icon(
                onPressed: _pickAvatar,
                icon: const Icon(Icons.photo_camera_outlined),
                label: const Text('Change photo'),
              ),
            ],
          ),
          const SizedBox(height: 20),
          const Text('Name'),
          const SizedBox(height: 6),
          TextField(controller: _nameCtrl, textInputAction: TextInputAction.next),
          const SizedBox(height: 16),
          Row(
            children: [
              const Expanded(child: Text('Username')),
              if (_checking)
                const SizedBox(width: 16, height: 16, child: CircularProgressIndicator(strokeWidth: 2)),
              if (!_checking && _available != null)
                Icon(
                  _available! ? Icons.check_circle : Icons.cancel,
                  size: 18,
                  color: _available! ? Colors.green : Colors.red,
                ),
            ],
          ),
          const SizedBox(height: 6),
          TextField(
            controller: _usernameCtrl,
            textInputAction: TextInputAction.next,
            decoration: const InputDecoration(prefixText: '@'),
          ),
          const SizedBox(height: 16),
          const Text('Skillset'),
          const SizedBox(height: 8),
          Wrap(
            spacing: 8,
            runSpacing: 8,
            children: _skillLabels.entries.map((e) {
              final selected = _skills.contains(e.key);
              return FilterChip(
                label: Text(e.value),
                selected: selected,
                onSelected: (v) {
                  setState(() {
                    if (v) {
                      _skills.add(e.key);
                    } else {
                      _skills.remove(e.key);
                      if (e.key == 'other') _otherSkillCtrl.clear();
                    }
                  });
                },
                shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(20)),
              );
            }).toList(),
          ),
          if (_otherSelected) ...[
            const SizedBox(height: 8),
            TextField(
              controller: _otherSkillCtrl,
              decoration: const InputDecoration(labelText: 'Other (please specify)'),
            ),
          ],
          const SizedBox(height: 16),
          const Text('Experience / Achievements'),
          const SizedBox(height: 6),
          TextField(
            controller: _bioCtrl,
            maxLines: 5,
            decoration: const InputDecoration(border: OutlineInputBorder()),
          ),
        ],
      ),
    );
  }
}
