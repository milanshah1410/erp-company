import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import 'core/providers.dart';
import 'core/storage/prefs_store.dart';
import 'core/theme/app_theme.dart';
import 'l10n/generated/app_localizations.dart';
import 'routes/app_router.dart';

/// The application shell: theme, language, routing.
///
/// It owns none of those decisions — it reads them. The language comes from the
/// user's preference, the routes from what the *server* says this user may open,
/// and (from Phase 2 onward) the brand colour from the tenant's white-label
/// settings. That is the shape of the whole app in one widget: almost nothing
/// here is decided at build time.
class KoshaApp extends ConsumerWidget {
  const KoshaApp({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final Locale locale = ref.watch(localeProvider);
    final ThemeMode themeMode = ref.watch(themeModeProvider);
    final GoRouter router = ref.watch(routerProvider);

    return MaterialApp.router(
      onGenerateTitle: (BuildContext context) => AppStrings.of(context).appTitle,
      debugShowCheckedModeBanner: false,

      theme: AppTheme.light(),
      darkTheme: AppTheme.dark(),
      themeMode: themeMode,

      locale: locale,
      supportedLocales: PrefsStore.supportedLocales,
      localizationsDelegates: const <LocalizationsDelegate<Object>>[
        AppStrings.delegate,
        GlobalMaterialLocalizations.delegate,
        GlobalWidgetsLocalizations.delegate,
        GlobalCupertinoLocalizations.delegate,
      ],

      routerConfig: router,

      /// Caps text scaling. Android lets a user set font size to 200%, and an
      /// unbounded scale turns a POS keypad into a screen where the total is off
      /// the bottom. 1.3 keeps large-text settings genuinely usable without
      /// breaking the layouts that matter most.
      builder: (BuildContext context, Widget? child) {
        final MediaQueryData media = MediaQuery.of(context);
        return MediaQuery(
          data: media.copyWith(
            textScaler: media.textScaler.clamp(maxScaleFactor: 1.3),
          ),
          child: child ?? const SizedBox.shrink(),
        );
      },
    );
  }
}
