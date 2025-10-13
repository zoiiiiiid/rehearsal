import 'dart:io';
import 'package:flutter/material.dart';
import '../services/api.dart';

class LoginPage extends StatefulWidget {
  const LoginPage({super.key});
  @override
  State<LoginPage> createState() => _LoginPageState();
}

class _LoginPageState extends State<LoginPage> {
  final _login = TextEditingController(); // email OR username
  final _password = TextEditingController();
  bool _loading = false;
  String? _err;

  @override
  void dispose() {
    _login.dispose();
    _password.dispose();
    super.dispose();
  }

  // Maps backend codes to user-safe messages.
  String _mapError(dynamic code) {
    final s = (code ?? '').toString();
    switch (s) {
      case 'INVALID_LOGIN':
        return 'Incorrect email or password';
      case 'INVALID_PASSWORD':
        return 'Incorrect email or password';
      case 'MISSING_FIELDS':
        return 'Enter your login and password';
      case 'TOKEN_INVALID':
        return 'Session expired, please log in again';
      default:
        return s.isEmpty ? 'Login failed' : 'Login failed';
    }
  }

  // User-safe error mapping for exceptions/transport.
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

  Future<void> _submit() async {
    final login = _login.text.trim();
    final pwd = _password.text;
    if (login.isEmpty || pwd.isEmpty) {
      setState(() => _err = 'MISSING_FIELDS');
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Enter your login and password')),
      );
      return;
    }

    setState(() {
      _loading = true;
      _err = null;
    });

    try {
      final res = await ApiService.postForm('login.php', {
        'login': login,
        'password': pwd,
      });
      if (!mounted) return;

      final token = res['token'];
      if (token is String && token.isNotEmpty) {
        await ApiService.saveToken(token);

        // Verify token once (query param avoids header races).
        final me = await ApiService.get('me.php?token=${Uri.encodeQueryComponent(token)}');
        if (!mounted) return;

        if (me['user'] != null) {
          ScaffoldMessenger.of(context).showSnackBar(const SnackBar(content: Text('Logged in')));
          Navigator.of(context).pushNamedAndRemoveUntil('/shell', (route) => false);
        } else {
          final msg = _mapError(me['error'] ?? 'TOKEN_INVALID');
          setState(() => _err = msg);
          ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(msg)));
        }
      } else {
        final msg = _mapError(res['error']);
        setState(() => _err = msg);
        ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(msg)));
      }
    } catch (e) {
      if (!mounted) return;
      final msg = _friendly(e, fallback: 'Login failed. Please try again.');
      setState(() => _err = msg);
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(msg)));
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final spacing = SizedBox(height: 14);
    final canSubmit = !_loading && _login.text.trim().isNotEmpty && _password.text.isNotEmpty;

    return Scaffold(
      body: SafeArea(
        child: Center(
          child: SingleChildScrollView(
            padding: const EdgeInsets.symmetric(horizontal: 24),
            child: Column(
              mainAxisAlignment: MainAxisAlignment.center,
              children: [
                const SizedBox(height: 48),
                const Text(
                  'RE:HEARSAL',
                  style: TextStyle(fontSize: 28, fontWeight: FontWeight.w700, fontStyle: FontStyle.italic),
                ),
                const SizedBox(height: 28),
                TextField(
                  controller: _login,
                  onChanged: (_) => setState(() {}),
                  decoration: const InputDecoration(
                    hintText: 'Email or username',
                    border: OutlineInputBorder(borderRadius: BorderRadius.all(Radius.circular(12))),
                    filled: true,
                  ),
                ),
                spacing,
                TextField(
                  controller: _password,
                  onChanged: (_) => setState(() {}),
                  obscureText: true,
                  decoration: const InputDecoration(
                    hintText: '••••••••',
                    border: OutlineInputBorder(borderRadius: BorderRadius.all(Radius.circular(12))),
                    filled: true,
                  ),
                ),
                const SizedBox(height: 18),
                SizedBox(
                  width: double.infinity,
                  child: FilledButton(
                    onPressed: canSubmit ? _submit : null,
                    child: Padding(
                      padding: const EdgeInsets.symmetric(vertical: 14),
                      child: _loading
                          ? const SizedBox(height: 18, width: 18, child: CircularProgressIndicator(strokeWidth: 2))
                          : const Text('LOGIN'),
                    ),
                  ),
                ),
                if (_err != null) ...[
                  const SizedBox(height: 12),
                  Text(_err!, textAlign: TextAlign.center),
                ],
                const SizedBox(height: 20),
                Row(
                  mainAxisAlignment: MainAxisAlignment.center,
                  children: [
                    const Text("Don't have an account?"),
                    TextButton(
                      onPressed: () => Navigator.pushNamed(context, '/register'),
                      child: const Text('Create one'),
                    ),
                  ],
                ),
                const SizedBox(height: 8),
                const Row(
                  mainAxisAlignment: MainAxisAlignment.center,
                  children: [Icon(Icons.g_mobiledata, size: 28), SizedBox(width: 8), Text('Google')],
                ),
                const SizedBox(height: 4),
                const Row(
                  mainAxisAlignment: MainAxisAlignment.center,
                  children: [Icon(Icons.facebook, size: 22), SizedBox(width: 8), Text('Facebook')],
                ),
                const SizedBox(height: 48),
              ],
            ),
          ),
        ),
      ),
    );
  }
}
