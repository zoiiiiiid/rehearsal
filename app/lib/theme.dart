import 'package:flutter/material.dart';

ThemeData buildAppTheme() {
  const black = Colors.black;
  const white = Colors.white;

  final scheme = const ColorScheme(
    brightness: Brightness.light,
    primary: black,
    onPrimary: white,
    secondary: black,
    onSecondary: white,
    error: Colors.red,
    onError: white,
    background: white,
    onBackground: black,
    surface: white,
    onSurface: black,
  );

  final base = ThemeData(useMaterial3: true, colorScheme: scheme);

  return base.copyWith(
    scaffoldBackgroundColor: white,
    appBarTheme: const AppBarTheme(
      color: white,
      elevation: 0,
      foregroundColor: black,
    ),
    textTheme: base.textTheme.apply(
      bodyColor: black,
      displayColor: black,
    ),
    inputDecorationTheme: const InputDecorationTheme(
      filled: true,
      fillColor: Color(0xFFF6F6F6),
      contentPadding: EdgeInsets.symmetric(horizontal: 16, vertical: 14),
      border: OutlineInputBorder(
        borderSide: BorderSide.none,
        borderRadius: BorderRadius.all(Radius.circular(14)),
      ),
      enabledBorder: OutlineInputBorder(
        borderSide: BorderSide.none,
        borderRadius: BorderRadius.all(Radius.circular(14)),
      ),
      focusedBorder: OutlineInputBorder(
        borderSide: BorderSide.none,
        borderRadius: BorderRadius.all(Radius.circular(14)),
      ),
    ),
    cardTheme: const CardThemeData(
      color: white,
      elevation: 0,
      margin: EdgeInsets.symmetric(horizontal: 12, vertical: 8),
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.all(Radius.circular(18)),
      ),
    ),
    navigationBarTheme: const NavigationBarThemeData(
      height: 68,
      indicatorColor: black,
      labelBehavior: NavigationDestinationLabelBehavior.onlyShowSelected,
      surfaceTintColor: white,
    ),
    iconTheme: const IconThemeData(color: black),
  );
}
