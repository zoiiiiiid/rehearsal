// lib/pages/register_page.dart
import 'dart:async';
import 'package:flutter/material.dart';
import '../services/api.dart';

class RegisterPage extends StatefulWidget {
  const RegisterPage({super.key});
  @override
  State<RegisterPage> createState() => _RegisterPageState();
}

class _RegisterPageState extends State<RegisterPage> {
  final _form = GlobalKey<FormState>();

  final _name = TextEditingController();
  final _username = TextEditingController();
  final _email = TextEditingController();
  final _password = TextEditingController();
  final _password2 = TextEditingController();

  // DOB (UI-only; not sent to API)
  final _dobCtrl = TextEditingController();
  DateTime? _dob;

  bool _loading = false;
  String? _err;
  bool _showPwd = false;
  bool _showPwd2 = false;

  // live username check
  Timer? _debUser;
  bool _checkingUser = false;
  bool? _userAvailable; // null = unknown

  @override
  void dispose() {
    _debUser?.cancel();
    _name.dispose();
    _username.dispose();
    _email.dispose();
    _password.dispose();
    _password2.dispose();
    _dobCtrl.dispose();
    super.dispose();
  }

  bool _emailOk(String v) {
    final s = v.trim();
    return s.contains('@') && s.contains('.');
  }

  bool _usernamePatternOk(String s) {
    if (s.length < 3 || s.length > 20) return false;
    return !RegExp('[^A-Za-z0-9_.]').hasMatch(s);
  }

  bool _is18OrOlder(DateTime dob) {
    final now = DateTime.now();
    final eighteen = DateTime(dob.year + 18, dob.month, dob.day);
    return !eighteen.isAfter(DateTime(now.year, now.month, now.day));
  }

  Future<void> _pickDob() async {
    final now = DateTime.now();
    final first = DateTime(now.year - 120, now.month, now.day);
    final last = now;
    final initial = _dob ?? DateTime(now.year - 18, now.month, now.day);

    final picked = await showDatePicker(
      context: context,
      firstDate: first,
      lastDate: last,
      initialDate: initial,
    );
    if (picked == null) return;

    setState(() {
      _dob = picked;
      _dobCtrl.text = '${picked.year.toString().padLeft(4, '0')}-'
          '${picked.month.toString().padLeft(2, '0')}-'
          '${picked.day.toString().padLeft(2, '0')}';
    });
  }

  Future<void> _checkUsername(String raw) async {
    final s = raw.trim().toLowerCase();
    _debUser?.cancel();
    if (!_usernamePatternOk(s)) {
      setState(() {
        _checkingUser = false;
        _userAvailable = null;
      });
      return;
    }
    setState(() {
      _checkingUser = true;
      _userAvailable = null;
    });
    _debUser = Timer(const Duration(milliseconds: 450), () async {
      final r = await ApiService.get('username_check.php?u=${Uri.encodeQueryComponent(s)}');
      if (!mounted) return;
      setState(() {
        _checkingUser = false;
        _userAvailable = r['available'] == true;
      });
    });
  }

  Future<void> _submit() async {
    final valid = _form.currentState?.validate() ?? false;
    if (!valid) return;

    if (_userAvailable == false) {
      setState(() => _err = 'Username is already taken');
      return;
    }

    setState(() {
      _loading = true;
      _err = null;
    });

    final r = await ApiService.post('register.php', {
      'name': _name.text.trim(),
      'username': _username.text.trim(),
      'email': _email.text.trim(),
      'password': _password.text,
      // Note: DOB intentionally not sent (UI-only age gate).
    });

    setState(() => _loading = false);

    if (r['ok'] == true) {
      if (mounted) Navigator.pushReplacementNamed(context, '/login');
    } else {
      final code = (r['error'] ?? 'Registration failed').toString();
      String msg;
      switch (code) {
        case 'EMAIL_TAKEN':
          msg = 'Email is already taken';
          break;
        case 'USERNAME_TAKEN':
          msg = 'Username is already taken';
          break;
        case 'INVALID_USERNAME':
          msg = 'Username must be 3–20 chars: letters, numbers, _ or .';
          break;
        case 'INVALID_EMAIL':
          msg = 'Please enter a valid email address';
          break;
        default:
          msg = code;
          break;
      }
      setState(() => _err = msg);
    }
  }

  InputDecoration _dec(String hint, {Widget? prefix, Widget? suffix}) => InputDecoration(
        hintText: hint,
        prefixIcon: prefix,
        suffixIcon: suffix,
        filled: true,
        border: const OutlineInputBorder(
          borderRadius: BorderRadius.all(Radius.circular(12)),
        ),
        isDense: true,
      );

  @override
  Widget build(BuildContext context) {
    final suffixUsername = _checkingUser
        ? const SizedBox(height: 18, width: 18, child: CircularProgressIndicator(strokeWidth: 2))
        : (_userAvailable == null
            ? null
            : Icon(
                _userAvailable == true ? Icons.check_circle : Icons.error,
                color: _userAvailable == true ? Colors.green : Colors.red,
              ));

    return Scaffold(
      body: SafeArea(
        child: Center(
          child: SingleChildScrollView(
            padding: const EdgeInsets.symmetric(horizontal: 24),
            child: Form(
              key: _form,
              autovalidateMode: AutovalidateMode.onUserInteraction,
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  const SizedBox(height: 32),
                  const Align(
                    alignment: Alignment.center,
                    child: Text(
                      'RE:HEARSAL',
                      style: TextStyle(
                        fontSize: 28,
                        fontWeight: FontWeight.w700,
                        fontStyle: FontStyle.italic,
                      ),
                    ),
                  ),
                  const SizedBox(height: 28),

                  // Name
                  TextFormField(
                    controller: _name,
                    textInputAction: TextInputAction.next,
                    decoration: _dec('Full name', prefix: const Icon(Icons.person_outline)),
                    validator: (v) => (v == null || v.trim().length < 2) ? 'Enter your name' : null,
                  ),
                  const SizedBox(height: 12),

                  // Username (live check)
                  TextFormField(
                    controller: _username,
                    textInputAction: TextInputAction.next,
                    onChanged: _checkUsername,
                    decoration: _dec(
                      'Username',
                      prefix: const Icon(Icons.alternate_email),
                      suffix: suffixUsername,
                    ),
                    validator: (v) {
                      final s = (v ?? '').trim();
                      if (s.isEmpty) return 'Enter a username';
                      if (!_usernamePatternOk(s)) return '3–20 letters, numbers, _ or .';
                      if (_userAvailable == false) return 'Username already taken';
                      return null;
                    },
                  ),
                  const SizedBox(height: 6),
                  const Text(
                    'Use letters, numbers, underscore or dot. 3–20 characters.',
                    style: TextStyle(fontSize: 12, color: Colors.black54),
                  ),
                  const SizedBox(height: 12),

                  // Email
                  TextFormField(
                    controller: _email,
                    keyboardType: TextInputType.emailAddress,
                    textInputAction: TextInputAction.next,
                    decoration: _dec('Email', prefix: const Icon(Icons.mail_outline)),
                    validator: (v) => (v == null || !_emailOk(v)) ? 'Enter a valid email' : null,
                  ),
                  const SizedBox(height: 12),

                  // DOB (must be 18+)
                  TextFormField(
                    controller: _dobCtrl,
                    readOnly: true,
                    onTap: _pickDob,
                    decoration: _dec('Date of birth (18+ required)', prefix: const Icon(Icons.cake_outlined), suffix: const Icon(Icons.calendar_today_outlined)),
                    validator: (_) {
                      if (_dob == null) return 'Select your date of birth';
                      if (!_is18OrOlder(_dob!)) return 'You must be at least 18 years old';
                      return null;
                    },
                  ),
                  const SizedBox(height: 12),

                  // Password
                  TextFormField(
                    controller: _password,
                    obscureText: !_showPwd,
                    decoration: _dec(
                      'Password',
                      prefix: const Icon(Icons.lock_outline),
                      suffix: IconButton(
                        icon: Icon(_showPwd ? Icons.visibility_off : Icons.visibility),
                        onPressed: () => setState(() => _showPwd = !_showPwd),
                      ),
                    ),
                    validator: (v) => (v == null || v.length < 8) ? 'At least 8 characters' : null,
                  ),
                  const SizedBox(height: 12),

                  // Confirm Password
                  TextFormField(
                    controller: _password2,
                    obscureText: !_showPwd2,
                    decoration: _dec(
                      'Confirm password',
                      prefix: const Icon(Icons.lock_reset_outlined),
                      suffix: IconButton(
                        icon: Icon(_showPwd2 ? Icons.visibility_off : Icons.visibility),
                        onPressed: () => setState(() => _showPwd2 = !_showPwd2),
                      ),
                    ),
                    validator: (v) => (v == null || v != _password.text) ? 'Passwords do not match' : null,
                  ),

                  const SizedBox(height: 18),
                  SizedBox(
                    width: double.infinity,
                    child: FilledButton(
                      onPressed: _loading ? null : _submit,
                      child: Padding(
                        padding: const EdgeInsets.symmetric(vertical: 14),
                        child: _loading
                            ? const SizedBox(height: 18, width: 18, child: CircularProgressIndicator(strokeWidth: 2))
                            : const Text('CREATE ACCOUNT'),
                      ),
                    ),
                  ),
                  if (_err != null)
                    Padding(
                      padding: const EdgeInsets.only(top: 12),
                      child: Text(_err!, style: const TextStyle(color: Colors.red)),
                    ),

                  const SizedBox(height: 18),
                  Row(
                    mainAxisAlignment: MainAxisAlignment.center,
                    children: [
                      const Text('Already have an account?'),
                      TextButton(
                        onPressed: () => Navigator.pushReplacementNamed(context, '/login'),
                        child: const Text('Log in'),
                      ),
                    ],
                  ),

                  const SizedBox(height: 8),
                  Row(
                    mainAxisAlignment: MainAxisAlignment.center,
                    children: const [Icon(Icons.g_mobiledata, size: 28), SizedBox(width: 8), Text('Google')],
                  ),
                  const SizedBox(height: 4),
                  Row(
                    mainAxisAlignment: MainAxisAlignment.center,
                    children: const [Icon(Icons.facebook, size: 22), SizedBox(width: 8), Text('Facebook')],
                  ),
                  const SizedBox(height: 40),
                ],
              ),
            ),
          ),
        ),
      ),
    );
  }
}
