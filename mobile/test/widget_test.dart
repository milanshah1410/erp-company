import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:kosha_mobile/core/errors/api_exception.dart';
import 'package:kosha_mobile/core/storage/prefs_store.dart';
import 'package:kosha_mobile/core/widgets/app_state_views.dart';
import 'package:kosha_mobile/l10n/generated/app_localizations.dart';

/// Widget tests (§31).
///
/// The behaviour under test is [AppErrorState]'s retry rule, which is a
/// judgement rather than a mechanism: a retry button is offered only where
/// pressing it could plausibly work. Offering "try again" on a permission
/// failure trains people to press a button that can never succeed, and offering
/// it on a validation failure hides the fact that the *input* has to change.
///
/// It is easy to "simplify" that away into always showing the button, so it is
/// pinned here.
void main() {
  Widget host(Widget child, {Locale locale = const Locale('en')}) => MaterialApp(
        locale: locale,
        supportedLocales: PrefsStore.supportedLocales,
        localizationsDelegates: const <LocalizationsDelegate<Object>>[
          AppStrings.delegate,
          GlobalMaterialLocalizations.delegate,
          GlobalWidgetsLocalizations.delegate,
          GlobalCupertinoLocalizations.delegate,
        ],
        home: Scaffold(body: child),
      );

  group('AppErrorState', () {
    testWidgets('offers retry for a network failure', (WidgetTester t) async {
      await t.pumpWidget(
        host(
          AppErrorState(
            error: const NetworkException('No internet connection.'),
            onRetry: () {},
          ),
        ),
      );

      expect(find.text('No internet connection.'), findsOneWidget);
      expect(find.text('Try again'), findsOneWidget);
      expect(find.byIcon(Icons.wifi_off_outlined), findsOneWidget);
    });

    testWidgets('does NOT offer retry for a permission failure',
        (WidgetTester t) async {
      await t.pumpWidget(
        host(
          AppErrorState(
            error: const ForbiddenException(
              'The Manufacturing module is not enabled for this workspace.',
              code: 'module_disabled',
            ),
            onRetry: () {},
          ),
        ),
      );

      expect(find.textContaining('Manufacturing'), findsOneWidget);
      expect(
        find.text('Try again'),
        findsNothing,
        reason: 'retrying a 403 can never succeed',
      );
      expect(find.byIcon(Icons.lock_outline), findsOneWidget);
    });

    testWidgets('does NOT offer retry for a validation failure',
        (WidgetTester t) async {
      await t.pumpWidget(
        host(
          AppErrorState(
            error: const ValidationException(
              'The given data was invalid.',
              <String, List<String>>{
                'email': <String>['Required.'],
              },
            ),
            onRetry: () {},
          ),
        ),
      );

      expect(find.text('Try again'), findsNothing);
    });

    testWidgets('shows the server message verbatim — it is already translated',
        (WidgetTester t) async {
      // The API honours X-Locale, so a business-rule message arrives in the
      // user's language. Re-wording it client-side would replace a specific,
      // translated explanation with a generic one.
      const String serverMessage = 'આ વર્કસ્પેસ સ્થગિત કરવામાં આવ્યું છે.';

      await t.pumpWidget(
        host(
          const AppErrorState(
            error: ForbiddenException(serverMessage, code: 'tenant_suspended'),
          ),
          locale: const Locale('gu'),
        ),
      );

      expect(find.text(serverMessage), findsOneWidget);
    });
  });

  group('AppEmptyState', () {
    testWidgets('renders title, body and an action', (WidgetTester t) async {
      await t.pumpWidget(
        host(
          AppEmptyState(
            title: 'Nothing here yet',
            body: 'When there is something to show, it will appear here.',
            action: OutlinedButton(onPressed: () {}, child: const Text('Add')),
          ),
        ),
      );

      expect(find.text('Nothing here yet'), findsOneWidget);
      expect(find.text('Add'), findsOneWidget);
    });
  });

  group('localization', () {
    testWidgets('Gujarati resolves rather than falling back to English',
        (WidgetTester t) async {
      late AppStrings strings;

      await t.pumpWidget(
        host(
          Builder(
            builder: (BuildContext context) {
              strings = AppStrings.of(context);
              return const SizedBox.shrink();
            },
          ),
          locale: const Locale('gu'),
        ),
      );

      expect(strings.localeName, 'gu');
      expect(strings.actionRetry, 'ફરી પ્રયાસ કરો');
    });

    testWidgets('Hindi resolves too', (WidgetTester t) async {
      late AppStrings strings;

      await t.pumpWidget(
        host(
          Builder(
            builder: (BuildContext context) {
              strings = AppStrings.of(context);
              return const SizedBox.shrink();
            },
          ),
          locale: const Locale('hi'),
        ),
      );

      expect(strings.actionRetry, 'फिर कोशिश करें');
    });
  });
}
