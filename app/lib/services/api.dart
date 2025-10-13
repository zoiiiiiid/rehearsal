import 'dart:async';
import 'dart:convert';
import 'dart:io';
import 'dart:typed_data';
import 'package:flutter/foundation.dart';
import 'package:http/http.dart' as http;
import 'package:shared_preferences/shared_preferences.dart';

class ApiService {
  // Use env var API_BASE for web; emulator IP for Android by default.
  static final String baseUrl = const String.fromEnvironment(
    'API_BASE',
    defaultValue:
        kIsWeb ? 'http://localhost/backend/api' : 'http://10.0.2.2:8000',
  );

  static const Duration timeout = Duration(seconds: 12);

  // ---- Token cache ---------------------------------------------------------
  static String? _tokenCache;

  static Future<String?> _token() async {
    if (_tokenCache != null) return _tokenCache;
    final sp = await SharedPreferences.getInstance();
    _tokenCache = sp.getString('token');
    return _tokenCache;
  }

  static Future<void> saveToken(String token) async {
    final sp = await SharedPreferences.getInstance();
    await sp.setString('token', token);
    _tokenCache = token;
  }

  static Future<void> clearToken() async {
    final sp = await SharedPreferences.getInstance();
    await sp.remove('token');
    _tokenCache = null;
  }

  static Uri _uri(String path, {String? token}) {
    final hasQ = path.contains('?');
    final t = token ?? _tokenCache;
    final suffix = (t != null && t.isNotEmpty)
        ? (hasQ ? '&' : '?') + 'token=' + Uri.encodeQueryComponent(t)
        : '';
    return Uri.parse('$baseUrl/$path$suffix');
  }

  static Map<String, dynamic> _decode(http.Response r) {
    try {
      return jsonDecode(r.body) as Map<String, dynamic>;
    } catch (_) {
      return {'error': 'BAD_JSON', 'status': r.statusCode, 'body': r.body};
    }
  }

  static Future<Map<String, dynamic>> get(String path) async {
    try {
      final t = await _token();
      final headers = <String, String>{'Accept': 'application/json'};
      // Avoid CORS preflight on web by not sending Authorization header.
      if (!kIsWeb && t != null) headers['Authorization'] = 'Bearer $t';

      final uri = _uri(path, token: t);
      if (kDebugMode) {
        debugPrint(
            '[Api] GET  $uri token? ${t != null} (authHdr:${headers.containsKey('Authorization')})');
      }

      final r = await http.get(uri, headers: headers).timeout(timeout);
      final m = _decode(r);
      if (r.statusCode >= 200 && r.statusCode < 300) return m;
      return m..putIfAbsent('status', () => r.statusCode);
    } on SocketException {
      return {'error': 'NETWORK', 'detail': 'SocketException (check server)'};
    } on TimeoutException {
      return {'error': 'TIMEOUT'};
    } catch (e) {
      return {'error': 'UNKNOWN', 'detail': e.toString()};
    }
  }

  static Future<Map<String, dynamic>> post(
    String path,
    Map<String, dynamic> body,
  ) async {
    try {
      final t = await _token();
      final headers = <String, String>{
        'Content-Type': 'application/json',
        'Accept': 'application/json',
      };
      if (!kIsWeb && t != null) headers['Authorization'] = 'Bearer $t';

      final uri = _uri(path, token: t);
      if (kDebugMode) {
        debugPrint(
            '[Api] POST(JSON) $uri token? ${t != null} (authHdr:${headers.containsKey('Authorization')})');
      }

      final r =
          await http.post(uri, headers: headers, body: jsonEncode(body)).timeout(
                timeout,
              );
      final m = _decode(r);
      if (r.statusCode >= 200 && r.statusCode < 300) return m;
      return m..putIfAbsent('status', () => r.statusCode);
    } on SocketException {
      return {'error': 'NETWORK', 'detail': 'SocketException (check server)'};
    } on TimeoutException {
      return {'error': 'TIMEOUT'};
    } catch (e) {
      return {'error': 'UNKNOWN', 'detail': e.toString()};
    }
  }

  static Future<Map<String, dynamic>> postForm(
    String path,
    Map<String, String> body,
  ) async {
    try {
      final t = await _token();
      final headers = <String, String>{'Accept': 'application/json'};
      // Avoid preflight on web by not sending Authorization.
      if (!kIsWeb && t != null) headers['Authorization'] = 'Bearer $t';

      final uri = _uri(path, token: t);
      if (kDebugMode) {
        debugPrint(
            '[Api] POST(FORM) $uri token? ${t != null} (authHdr:${headers.containsKey('Authorization')})');
      }

      final r =
          await http.post(uri, headers: headers, body: body).timeout(timeout);
      final m = _decode(r);
      if (r.statusCode >= 200 && r.statusCode < 300) return m;
      return m..putIfAbsent('status', () => r.statusCode);
    } on SocketException {
      return {'error': 'NETWORK', 'detail': 'SocketException (check server)'};
    } on TimeoutException {
      return {'error': 'TIMEOUT'};
    } catch (e) {
      return {'error': 'UNKNOWN', 'detail': e.toString()};
    }
  }

  // ---- Multipart upload (images / videos / files) --------------------------
  // Works on web + mobile. Sends token in query and as a form field.
  static Future<Map<String, dynamic>> uploadMultipart(
    String path, {
    required String fieldName,
    String? filename,
    List<int>? bytes,
    String? filePath,
    Map<String, String>? fields,
  }) async {
    try {
      final t = await _token();
      final uri = _uri(path, token: t);
      if (kDebugMode) debugPrint('[Api] UPLOAD $uri token? ${t != null}');

      final req = http.MultipartRequest('POST', uri);
      if (!kIsWeb && t != null) req.headers['Authorization'] = 'Bearer $t';
      req.headers['Accept'] = 'application/json';
      if (t != null) req.fields['token'] = t; // PHP multipart fallback
      if (fields != null && fields.isNotEmpty) req.fields.addAll(fields);

      if (bytes != null) {
        req.files.add(http.MultipartFile.fromBytes(
          fieldName,
          bytes,
          filename: filename ?? 'upload.bin',
        ));
      } else if (filePath != null) {
        req.files.add(await http.MultipartFile.fromPath(
          fieldName,
          filePath,
          filename: filename,
        ));
      } else {
        return {'error': 'NO_FILE'};
      }

      final streamed = await req.send().timeout(timeout);
      final r = await http.Response.fromStream(streamed);
      return _decode(r);
    } on SocketException {
      return {'error': 'NETWORK', 'detail': 'SocketException (check server)'};
    } on TimeoutException {
      return {'error': 'TIMEOUT'};
    } catch (e) {
      return {'error': 'UNKNOWN', 'detail': e.toString()};
    }
  }

  static Future<Map<String, dynamic>> upload(
    String path, {
    required String field,
    required String filename,
    required Uint8List bytes,
    Map<String, String>? fields,
  }) {
    return uploadMultipart(
      path,
      fieldName: field,
      filename: filename,
      bytes: bytes,
      fields: fields,
    );
  }

  // -------------------- Messaging --------------------
  static Future<List<dynamic>> listConversations() async {
    final r = await get('conversations_list.php');
    if (r['items'] is List) return r['items'] as List<dynamic>;
    throw Exception(r['error'] ?? 'SERVER');
  }

  static Future<List<dynamic>> listMessages(
    int conversationId, {
    int? sinceId,
    int limit = 50,
  }) async {
    final q = 'conversation_id=$conversationId'
        '${sinceId != null ? '&since_id=$sinceId' : ''}&limit=$limit';
    final r = await get('messages_list.php?$q');
    if (r['items'] is List) return r['items'] as List<dynamic>;
    throw Exception(r['error'] ?? 'SERVER');
  }

  static Future<int> sendTextMessage({
    required String receiverId,
    required String content,
  }) async {
    final r = await post('messages_send.php', {
      'receiver_id': receiverId,
      'content': content,
    });
    if (r['ok'] == true) return (r['conversation_id'] as num).toInt();
    throw Exception(r['error'] ?? 'SERVER');
  }

  static Future<void> markRead({
    required int conversationId,
    required int lastId,
  }) async {
    try {
      final r = await post('messages_mark_read.php', {
        'conversation_id': conversationId,
        'last_id': lastId,
      });
      if (r['ok'] != true && kDebugMode) {
        debugPrint('[Api] markRead failed: ${r['error'] ?? r}');
      }
    } catch (e) {
      if (kDebugMode) debugPrint('[Api] markRead exception: $e');
    }
  }

  /// Send image/video in DM.
  static Future<Map<String, dynamic>> sendMediaMessage({
    String? receiverId,
    int? conversationId,
    required String filename,
    Uint8List? bytes,
    String? filePath,
    String? caption,
  }) async {
    final fields = <String, String>{};
    if (receiverId != null && receiverId.isNotEmpty) {
      fields['receiver_id'] = receiverId;
    }
    if (conversationId != null) {
      fields['conversation_id'] = '$conversationId';
    }
    if (caption != null && caption.trim().isNotEmpty) {
      fields['caption'] = caption.trim();
    }

    // Defensive: make sure at least one routing field is present
    if (!fields.containsKey('receiver_id') &&
        !fields.containsKey('conversation_id')) {
      return {
        'error': 'BAD_REQUEST',
        'detail': 'conversation_id or receiver_id required'
      };
    }

    final r = await uploadMultipart(
      'messages_send_media.php',
      fieldName: 'file',
      filename: filename,
      bytes: bytes,
      filePath: filePath,
      fields: fields,
    );

    return r;
  }

  // -------------------- Admin (mentor approvals) --------------------
  static Future<List<dynamic>> adminPending({
    String? q,
    int page = 1,
    int limit = 50,
  }) async {
    final qp = <String>[]..add('page=$page')..add('limit=$limit');
    if (q != null && q.trim().isNotEmpty) {
      qp.add('q=${Uri.encodeQueryComponent(q.trim())}');
    }
    final res = await get('admin_pending_list.php?${qp.join('&')}');
    final items = res['items'];
    if (items is List) return items;
    return const [];
  }

  static Future<bool> adminVerify(String userId) async {
    final r = await postForm('mentor_verify.php', {'user_id': userId});
    return r['ok'] == true;
  }

  static Future<bool> adminReject(String userId) async {
    final r = await postForm('mentor_reject.php', {'user_id': userId});
    return r['ok'] == true;
  }

  // -------------------- Workshops: Model B join flow ------------------------

  /// Unified access endpoint:
  static Future<Map<String, dynamic>> workshopAccess({
    String? qr,
    String? workshopId,
    String? token,
  }) async {
    final body = <String, dynamic>{};
    if (qr != null && qr.isNotEmpty) body['qr'] = qr;
    if (workshopId != null && workshopId.isNotEmpty) {
      body['workshop_id'] = workshopId;
    }
    if (token != null && token.isNotEmpty) body['token'] = token;
    return post('workshop_access.php', body);
  }

  /// Free workshop → claim a seat (capacity enforced server-side).
  /// Returns { ok, join_url?, counts? } or { error:'FULL'|'NOT_FOUND'... }
  static Future<Map<String, dynamic>> claimFree({required String wid}) async {
    return workshopAccess(workshopId: wid);
  }

  static Future<Map<String, dynamic>> claimWithToken(
      {required String token}) async {
    return workshopAccess(token: token);
  }

  static Future<Map<String, dynamic>> attendanceGetToken({
    required String workshopId,
  }) async {
    final r = await get(
        'attendance_token.php?workshop_id=${Uri.encodeQueryComponent(workshopId)}');
    return r; // { ok, payload, expires_at, workshop_id }
  }

  static Future<Map<String, dynamic>> attendanceScan({
    required String workshopId,
    required String payload, // raw QR string
  }) async {
    final r = await post('attendance_scan.php', {
      'workshop_id': workshopId,
      'payload': payload,
    });
    return r; // { ok, status: checked_in|already|invalid|not_found|paid_required, user: {...}? }
  }

  // -------------------- Admin Analytics --------------------
  static Future<Map<String, dynamic>> adminAnalyticsOverview(
      {String range = '30d'}) async {
    final r = await get('admin_analytics_overview.php?range=$range');
    return r;
  }

  static Future<Map<String, dynamic>> adminAnalyticsSeries(
      {String range = '30d'}) async {
    final r = await get('admin_analytics_series.php?range=$range');
    return r;
  }

  static Future<Map<String, dynamic>> adminTopWorkshops(
      {String range = '30d', int limit = 10}) async {
    final r =
        await get('admin_analytics_top_workshops.php?range=$range&limit=$limit');
    return r;
  }

  static Future<Map<String, dynamic>> adminTopMentors(
      {String range = '30d', int limit = 10}) async {
    final r =
        await get('admin_analytics_top_mentors.php?range=$range&limit=$limit');
    return r;
  }
}
