import 'package:flutter/material.dart';
import 'package:shared_preferences/shared_preferences.dart';

/// Non-sensitive preferences (§27 theme, §28 language).
///
/// Deliberately separate from [SecureStore] and deliberately unable to hold a
/// token: SharedPreferences is world-readable on a rooted device, and the way to
/// stop credentials drifting into it is to give the class no method that would
/// accept them.
///
/// Read synchronously after [load], because the theme and locale are needed to
/// build the very first frame — an app that flashes English-on-white before
/// settling into Gujarati-on-dark looks broken.
class PrefsStore {
  PrefsStore(this._prefs);

  static Future<PrefsStore> load() async =>
      PrefsStore(await SharedPreferences.getInstance());

  final SharedPreferences _prefs;

  static const String _localeKey = 'locale';
  static const String _themeKey = 'theme_mode';

  /// The languages this app ships (§28). Gujarati and Hindi are first-class
  /// here, not an afterthought: the shop counters this is built for run in them.
  static const List<Locale> supportedLocales = <Locale>[
    Locale('en'),
    Locale('hi'),
    Locale('gu'),
  ];

  /// The value sent as `X-Locale` on every request, so server-side validation
  /// messages come back in the same language as the UI.
  String get localeCode => _prefs.getString(_localeKey) ?? 'en';

  Locale get locale => Locale(localeCode);

  Future<void> setLocale(String code) => _prefs.setString(_localeKey, code);

  ThemeMode get themeMode => switch (_prefs.getString(_themeKey)) {
        'light' => ThemeMode.light,
        'dark' => ThemeMode.dark,
        _ => ThemeMode.system,
      };

  Future<void> setThemeMode(ThemeMode mode) =>
      _prefs.setString(_themeKey, mode.name);
}
