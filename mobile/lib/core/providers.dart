import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import 'auth/token_store.dart';
import 'network/api_client.dart';
import 'storage/prefs_store.dart';

/// The container's roots (§2).
///
/// Anything the whole app needs and nothing owns lives here. Feature-specific
/// providers stay in their feature folder — this file is not a dumping ground,
/// it is the short list of things that genuinely have no better home.

/// Overridden in `main()` once SharedPreferences has loaded.
///
/// Deliberately throws rather than returning a default: a silent fallback would
/// mean the app reading a fresh, empty preference store and quietly forgetting
/// the user's language every launch, which is far harder to notice than a crash
/// on the first line of `main`.
final Provider<PrefsStore> prefsStoreProvider = Provider<PrefsStore>(
  (Ref ref) => throw UnimplementedError(
    'prefsStoreProvider must be overridden in main() with PrefsStore.load().',
  ),
);

/// The UI language, and the value sent as `X-Locale` on every request.
class LocaleController extends Notifier<Locale> {
  @override
  Locale build() => ref.read(prefsStoreProvider).locale;

  Future<void> set(String code) async {
    await ref.read(prefsStoreProvider).setLocale(code);
    state = Locale(code);
  }
}

final NotifierProvider<LocaleController, Locale> localeProvider =
    NotifierProvider<LocaleController, Locale>(LocaleController.new);

class ThemeModeController extends Notifier<ThemeMode> {
  @override
  ThemeMode build() => ref.read(prefsStoreProvider).themeMode;

  Future<void> set(ThemeMode mode) async {
    await ref.read(prefsStoreProvider).setThemeMode(mode);
    state = mode;
  }
}

final NotifierProvider<ThemeModeController, ThemeMode> themeModeProvider =
    NotifierProvider<ThemeModeController, ThemeMode>(ThemeModeController.new);

/// The HTTP client, wired to the session.
///
/// Built once and kept: rebuilding it would drop in-flight requests and reset
/// the connection pool. It reads the token through a callback rather than
/// closing over a value, so a sign-in takes effect on the very next request
/// without the client being recreated.
///
/// `onUnauthorized` clears the session centrally. Every screen then reacts to
/// the token going null, instead of each one having to notice its own 401 —
/// which is the version where one screen forgets.
final Provider<ApiClient> apiClientProvider = Provider<ApiClient>((Ref ref) {
  final TokenStore tokens = ref.read(tokenStoreProvider.notifier);

  return ApiClient(
    tokenReader: tokens.read,
    localeReader: () => ref.read(localeProvider).languageCode,
    onUnauthorized: tokens.clear,
  );
});
