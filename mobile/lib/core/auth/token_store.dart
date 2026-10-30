import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../storage/secure_store.dart';

/// Holds the bearer token: in memory for speed, in the keychain for durability.
///
/// Core rather than a feature, because two things need it and they must not need
/// each other: [ApiClient] reads it on every request, and the sign-in feature
/// writes it. Routing the dependency through here is what stops the network
/// layer importing the auth feature and the auth feature importing the network
/// layer.
///
/// The in-memory copy matters. Reading the keychain is a platform channel round
/// trip, and doing one per HTTP request would put a hop on the critical path of
/// every screen — including a POS lookup, where it is felt.
class TokenStore extends Notifier<String?> {
  late final SecureStore _secure = ref.read(secureStoreProvider);

  @override
  String? build() => null;

  /// Loads the persisted token at launch. Returns whether one was found, which
  /// is what decides between the sign-in screen and the app.
  Future<bool> restore() async {
    final String? token = await _secure.readToken();
    state = token;
    return token != null && token.isNotEmpty;
  }

  Future<void> save(String token) async {
    state = token;
    await _secure.writeToken(token);
  }

  /// Sign-out, a rejected token, or a password reset.
  ///
  /// Clears memory first: the next request must not be able to send a token we
  /// have already decided is dead, even if the keychain write is slow or fails.
  Future<void> clear() async {
    state = null;
    await _secure.clearAll();
  }

  /// What [ApiClient] calls per request — synchronous in practice, since the
  /// value is already in memory after [restore].
  Future<String?> read() async => state;
}

final Provider<SecureStore> secureStoreProvider = Provider<SecureStore>(
  (Ref ref) => SecureStore.standard(),
);

final NotifierProvider<TokenStore, String?> tokenStoreProvider =
    NotifierProvider<TokenStore, String?>(TokenStore.new);
