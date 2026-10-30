import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import 'app.dart';
import 'core/auth/token_store.dart';
import 'core/providers.dart';
import 'core/storage/prefs_store.dart';

/// Entry point. Deliberately thin (§32 "do not put everything in main.dart") —
/// its whole job is to get the three things the first frame depends on ready
/// before that frame is built.
Future<void> main() async {
  WidgetsFlutterBinding.ensureInitialized();

  // Portrait only, for now. The screens this app is built around are used
  // one-handed at a counter, and every landscape layout is a second layout to
  // maintain for a case nobody asked for. Revisit if a tablet POS is wanted.
  await SystemChrome.setPreferredOrientations(<DeviceOrientation>[
    DeviceOrientation.portraitUp,
  ]);

  // Read before the first frame so the app opens in the user's language and
  // theme rather than flashing English-on-white and then correcting itself.
  final PrefsStore prefs = await PrefsStore.load();

  final ProviderContainer container = ProviderContainer(
    overrides: <Override>[prefsStoreProvider.overrideWithValue(prefs)],
  );

  // Restore the session before the first frame too, so the app opens directly
  // on the dashboard for a signed-in user instead of showing sign-in and then
  // replacing it.
  await container.read(tokenStoreProvider.notifier).restore();

  runApp(
    UncontrolledProviderScope(
      container: container,
      child: const KoshaApp(),
    ),
  );
}
