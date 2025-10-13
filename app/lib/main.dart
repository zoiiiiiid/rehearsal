// lib/main.dart
import 'package:flutter/material.dart';
import 'package:rehearsal_app/pages/admin_reports_page.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'theme.dart';
import 'pages/login_page.dart';
import 'pages/register_page.dart';
import 'pages/shell.dart';
import 'pages/public_profile_page.dart';
import 'pages/profile_edit_page.dart';
import 'pages/notifications_page.dart';

// Messaging + admin + workshop detail
import 'pages/inbox_page.dart';
import 'pages/chat_page.dart';
import 'pages/admin_approvals_page.dart'; // FIX: correct file
import 'pages/workshop_detail_page.dart';

void main() => runApp(const App());

class App extends StatelessWidget {
  const App({super.key});

  Future<bool> _hasToken() async {
    final sp = await SharedPreferences.getInstance();
    return sp.getString('token') != null;
  }

  int? _toInt(dynamic v) {
    if (v == null) return null;
    if (v is int) return v;
    if (v is num) return v.toInt();
    final s = v.toString();
    return s.isEmpty ? null : int.tryParse(s);
  }

  @override
  Widget build(BuildContext context) {
    return MaterialApp(
      title: 'Re:hearsal',
      debugShowCheckedModeBanner: false,
      theme: buildAppTheme(),
      routes: {
        '/login': (_) => const LoginPage(),
        '/register': (_) => const RegisterPage(),
        '/shell': (_) => const Shell(),
        '/profile_edit': (_) => const ProfileEditPage(),
        '/notifications': (_) => const NotificationsPage(),
        // Static routes
        '/inbox': (_) => const InboxPage(),
        '/admin': (_) => const AdminApprovalsPage(),
        '/admin_reports': (_) => const AdminReportsPage(),
      },
      onGenerateRoute: (settings) {
        final name = settings.name;

        // /user with args: {'id': String}
        if (name == '/user') {
          String? id;
          final args = settings.arguments;
          if (args is Map && args['id'] != null) id = args['id'].toString();
          if (id == null || id.isEmpty) {
            return MaterialPageRoute(
              builder: (_) => const _RouteError(message: 'Missing user id for /user'),
            );
          }
          return MaterialPageRoute(builder: (_) => PublicProfilePage(userId: id!));
        }

        // /chat with args: {'otherUserId': String, 'conversationId': int?}
        if (name == '/chat') {
          final args = settings.arguments;
          if (args is! Map || args['otherUserId'] == null) {
            return MaterialPageRoute(
              builder: (_) => const _RouteError(message: 'Missing otherUserId for /chat'),
            );
          }
          final otherUserId = args['otherUserId'].toString();
          final conversationId = _toInt(args['conversationId']);
          return MaterialPageRoute(
            builder: (_) => ChatPage(otherUserId: otherUserId, conversationId: conversationId),
          );
        }

        // /workshop_detail with args: {'id': String}
        if (name == '/workshop_detail') {
          String? id;
          final args = settings.arguments;
          if (args is Map && args['id'] != null) id = args['id'].toString();
          return MaterialPageRoute(builder: (_) => WorkshopDetailPage(workshopId: id));
        }

        return null; // fallback to routes table
      },
      home: FutureBuilder<bool>(
        future: _hasToken(),
        builder: (c, s) {
          if (!s.hasData) {
            return const Scaffold(body: Center(child: CircularProgressIndicator()));
          }
          return s.data! ? const Shell() : const LoginPage();
        },
      ),
    );
  }
}

class _RouteError extends StatelessWidget {
  final String message;
  const _RouteError({required this.message});

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Route error')),
      body: Center(child: Text(message)),
    );
  }
}
