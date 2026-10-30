import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../../core/errors/api_exception.dart';
import '../../../core/widgets/app_text_field.dart';
import '../../../l10n/generated/app_localizations.dart';
import '../../../routes/screen_routes.dart';
import '../application/session_controller.dart';

/// Sign-in (§4).
///
/// One detail here is worth stating because it is counter-intuitive and easy to
/// "fix" wrongly: **bad credentials arrive as a 422, not a 401.** The Laravel
/// controller throws a `ValidationException`, so the failure lands in
/// `errors.email` and is rendered under the email field like any other
/// validation message. A 401 on this screen would mean something else entirely.
class LoginScreen extends ConsumerStatefulWidget {
  const LoginScreen({super.key});

  @override
  ConsumerState<LoginScreen> createState() => _LoginScreenState();
}

class _LoginScreenState extends ConsumerState<LoginScreen> {
  final GlobalKey<FormState> _form = GlobalKey<FormState>();
  final TextEditingController _email = TextEditingController();
  final TextEditingController _password = TextEditingController();

  bool _busy = false;
  bool _obscure = true;
  Map<String, List<String>> _fieldErrors = const <String, List<String>>{};
  String? _generalError;

  @override
  void dispose() {
    _email.dispose();
    _password.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    if (!(_form.currentState?.validate() ?? false)) {
      return;
    }

    setState(() {
      _busy = true;
      _fieldErrors = const <String, List<String>>{};
      _generalError = null;
    });

    try {
      await ref.read(sessionProvider.notifier).signIn(
            email: _email.text.trim(),
            password: _password.text,
          );
      // No navigation here on purpose — the router's redirect reacts to the
      // session changing. A push from this screen would race it.
    } on ValidationException catch (e) {
      setState(() => _fieldErrors = e.errors);
    } on ApiException catch (e) {
      setState(() => _generalError = e.message);
    } finally {
      if (mounted) {
        setState(() => _busy = false);
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final AppStrings strings = AppStrings.of(context);
    final ThemeData theme = Theme.of(context);

    return Scaffold(
      body: SafeArea(
        child: Center(
          child: SingleChildScrollView(
            padding: const EdgeInsets.all(24),
            child: ConstrainedBox(
              // Caps the form on a tablet: a text field the full width of a
              // 10-inch screen is unpleasant to read and to aim at.
              constraints: const BoxConstraints(maxWidth: 420),
              child: AutofillGroup(
                child: Form(
                  key: _form,
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.stretch,
                    children: <Widget>[
                      Text(
                        strings.authSignInTitle,
                        style: theme.textTheme.headlineMedium,
                      ),
                      const SizedBox(height: 8),
                      Text(
                        strings.authSignInSubtitle,
                        style: theme.textTheme.bodyMedium?.copyWith(
                          color: theme.colorScheme.onSurfaceVariant,
                        ),
                      ),
                      const SizedBox(height: 32),

                      AppTextField(
                        controller: _email,
                        label: strings.fieldEmail,
                        keyboardType: TextInputType.emailAddress,
                        textInputAction: TextInputAction.next,
                        autofillHints: const <String>[AutofillHints.username],
                        prefixIcon: const Icon(Icons.mail_outline),
                        serverError: _fieldErrors['email']?.first,
                        enabled: !_busy,
                        validator: (String? v) => (v == null || v.trim().isEmpty)
                            ? strings.validationRequired(strings.fieldEmail)
                            : null,
                      ),
                      const SizedBox(height: 16),

                      AppTextField(
                        controller: _password,
                        label: strings.fieldPassword,
                        obscure: _obscure,
                        textInputAction: TextInputAction.done,
                        autofillHints: const <String>[AutofillHints.password],
                        prefixIcon: const Icon(Icons.lock_outline),
                        suffixIcon: IconButton(
                          icon: Icon(
                            _obscure
                                ? Icons.visibility_outlined
                                : Icons.visibility_off_outlined,
                          ),
                          onPressed: () =>
                              setState(() => _obscure = !_obscure),
                        ),
                        serverError: _fieldErrors['password']?.first,
                        enabled: !_busy,
                        onSubmitted: (_) => _submit(),
                        validator: (String? v) => (v == null || v.isEmpty)
                            ? strings.validationRequired(strings.fieldPassword)
                            : null,
                      ),

                      if (_generalError != null) ...<Widget>[
                        const SizedBox(height: 16),
                        Text(
                          _generalError!,
                          style: theme.textTheme.bodyMedium
                              ?.copyWith(color: theme.colorScheme.error),
                        ),
                      ],

                      const SizedBox(height: 24),
                      FilledButton(
                        onPressed: _busy ? null : _submit,
                        child: _busy
                            ? const SizedBox(
                                width: 20,
                                height: 20,
                                child: CircularProgressIndicator(
                                  strokeWidth: 2,
                                ),
                              )
                            : Text(strings.actionSignIn),
                      ),
                      const SizedBox(height: 8),
                      TextButton(
                        onPressed: _busy
                            ? null
                            : () => context.push(AppRoutes.forgotPassword),
                        child: Text(strings.authForgotPassword),
                      ),
                    ],
                  ),
                ),
              ),
            ),
          ),
        ),
      ),
    );
  }
}
