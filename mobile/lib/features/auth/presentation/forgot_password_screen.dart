import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../../core/errors/api_exception.dart';
import '../../../core/widgets/app_text_field.dart';
import '../../../l10n/generated/app_localizations.dart';
import '../data/auth_repository.dart';

/// Forgot password, then reset with the emailed code (§4).
///
/// Both steps live on one screen because they are one errand: the code arrives
/// while the user is still holding the phone, and sending them back through a
/// second screen to type it is friction for no gain.
///
/// **The confirmation is deliberately non-committal** — "if that email belongs
/// to an account…". The API refuses to reveal whether an address is registered,
/// and the UI must not undo that by wording the success case as though the
/// address were found.
class ForgotPasswordScreen extends ConsumerStatefulWidget {
  const ForgotPasswordScreen({super.key});

  @override
  ConsumerState<ForgotPasswordScreen> createState() =>
      _ForgotPasswordScreenState();
}

class _ForgotPasswordScreenState extends ConsumerState<ForgotPasswordScreen> {
  final TextEditingController _email = TextEditingController();
  final TextEditingController _token = TextEditingController();
  final TextEditingController _password = TextEditingController();

  bool _busy = false;
  bool _codeSent = false;
  String? _notice;
  String? _error;
  Map<String, List<String>> _fieldErrors = const <String, List<String>>{};

  @override
  void dispose() {
    _email.dispose();
    _token.dispose();
    _password.dispose();
    super.dispose();
  }

  Future<void> _run(Future<String> Function() action, {bool advance = false}) async {
    setState(() {
      _busy = true;
      _error = null;
      _fieldErrors = const <String, List<String>>{};
    });

    try {
      final String message = await action();
      if (!mounted) {
        return;
      }
      setState(() {
        _notice = message;
        if (advance) {
          _codeSent = true;
        }
      });
    } on ValidationException catch (e) {
      setState(() => _fieldErrors = e.errors);
    } on ApiException catch (e) {
      setState(() => _error = e.message);
    } finally {
      if (mounted) {
        setState(() => _busy = false);
      }
    }
  }

  Future<void> _sendCode() => _run(
        () => ref.read(authRepositoryProvider).forgotPassword(_email.text.trim()),
        advance: true,
      );

  Future<void> _reset() async {
    await _run(
      () => ref.read(authRepositoryProvider).resetPassword(
            email: _email.text.trim(),
            token: _token.text.trim(),
            password: _password.text,
          ),
    );

    // A successful reset revokes every token server-side, so there is nothing to
    // sign in with from here — back to the sign-in screen, which is where the
    // user has to end up anyway.
    if (mounted && _error == null && _fieldErrors.isEmpty) {
      context.pop();
    }
  }

  @override
  Widget build(BuildContext context) {
    final AppStrings strings = AppStrings.of(context);
    final ThemeData theme = Theme.of(context);

    return Scaffold(
      appBar: AppBar(
        title: Text(_codeSent ? strings.authResetTitle : strings.authForgotTitle),
      ),
      body: SafeArea(
        child: SingleChildScrollView(
          padding: const EdgeInsets.all(24),
          child: Center(
            child: ConstrainedBox(
              constraints: const BoxConstraints(maxWidth: 420),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: <Widget>[
                  Text(
                    strings.authForgotSubtitle,
                    style: theme.textTheme.bodyMedium?.copyWith(
                      color: theme.colorScheme.onSurfaceVariant,
                    ),
                  ),
                  const SizedBox(height: 24),

                  AppTextField(
                    controller: _email,
                    label: strings.fieldEmail,
                    keyboardType: TextInputType.emailAddress,
                    prefixIcon: const Icon(Icons.mail_outline),
                    // Locked once the code is out: changing the address now
                    // would submit a code minted for a different account.
                    enabled: !_busy && !_codeSent,
                    serverError: _fieldErrors['email']?.first,
                  ),

                  if (_codeSent) ...<Widget>[
                    const SizedBox(height: 16),
                    AppTextField(
                      controller: _token,
                      label: strings.fieldResetCode,
                      prefixIcon: const Icon(Icons.key_outlined),
                      enabled: !_busy,
                      serverError: _fieldErrors['token']?.first,
                    ),
                    const SizedBox(height: 16),
                    AppTextField(
                      controller: _password,
                      label: strings.fieldNewPassword,
                      obscure: true,
                      prefixIcon: const Icon(Icons.lock_outline),
                      enabled: !_busy,
                      serverError: _fieldErrors['password']?.first,
                    ),
                  ],

                  if (_notice != null) ...<Widget>[
                    const SizedBox(height: 16),
                    Text(_notice!, style: theme.textTheme.bodyMedium),
                  ],
                  if (_error != null) ...<Widget>[
                    const SizedBox(height: 16),
                    Text(
                      _error!,
                      style: theme.textTheme.bodyMedium
                          ?.copyWith(color: theme.colorScheme.error),
                    ),
                  ],

                  const SizedBox(height: 24),
                  FilledButton(
                    onPressed: _busy ? null : (_codeSent ? _reset : _sendCode),
                    child: Text(
                      _codeSent
                          ? strings.actionResetPassword
                          : strings.actionSendCode,
                    ),
                  ),
                ],
              ),
            ),
          ),
        ),
      ),
    );
  }
}
