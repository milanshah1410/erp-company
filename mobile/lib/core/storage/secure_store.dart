import 'package:flutter_secure_storage/flutter_secure_storage.dart';

/// Storage for things that must not be readable off a lost handset (§4, §25).
///
/// Backed by the iOS Keychain and Android EncryptedSharedPreferences. §4 is
/// explicit that authentication credentials must not sit in plain
/// SharedPreferences, so the split is enforced by having two classes: anything
/// that belongs here has no API in [PrefsStore] to accidentally end up in.
///
/// What lives here is the bearer token and nothing else. Not the user's profile,
/// not their permissions — those are re-fetched from `/api/me` on launch, and
/// caching a permission list on the device would mean a revoked permission
/// staying granted until the next successful sync.
class SecureStore {
  const SecureStore(this._storage);

  factory SecureStore.standard() => const SecureStore(
        FlutterSecureStorage(
          aOptions: AndroidOptions(encryptedSharedPreferences: true),
          iOptions: IOSOptions(
            // Readable only after the first unlock following a reboot, and never
            // included in an iCloud/iTunes backup — a token restored onto a
            // different handset is a session on a device we never authenticated.
            accessibility: KeychainAccessibility.first_unlock_this_device,
          ),
        ),
      );

  final FlutterSecureStorage _storage;

  static const String _tokenKey = 'auth_token';

  Future<String?> readToken() => _storage.read(key: _tokenKey);

  Future<void> writeToken(String token) =>
      _storage.write(key: _tokenKey, value: token);

  Future<void> clearToken() => _storage.delete(key: _tokenKey);

  /// Sign-out and password-reset both land here.
  ///
  /// Deletes rather than overwrites: a key that is merely blanked still exists,
  /// and "is there a token?" is the question the launch path asks.
  Future<void> clearAll() => _storage.deleteAll();
}
