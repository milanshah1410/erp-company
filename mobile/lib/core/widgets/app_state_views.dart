import 'package:flutter/material.dart';

import '../../l10n/generated/app_localizations.dart';
import '../errors/api_exception.dart';

/// The three states every list and detail screen must handle (§2: loading,
/// error, empty), in one file so they look identical everywhere.
///
/// A screen that invents its own empty state is a screen that will word it
/// differently, size it differently, and forget the retry button.

/// Busy.
///
/// No text by default: a spinner under a label that says "Loading…" tells the
/// user nothing the spinner did not, and on a fast connection it flashes.
class AppLoading extends StatelessWidget {
  const AppLoading({super.key, this.message});

  final String? message;

  @override
  Widget build(BuildContext context) => Center(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: <Widget>[
            const SizedBox(
              width: 28,
              height: 28,
              child: CircularProgressIndicator(strokeWidth: 2.5),
            ),
            if (message != null) ...<Widget>[
              const SizedBox(height: 16),
              Text(message!, style: Theme.of(context).textTheme.bodyMedium),
            ],
          ],
        ),
      );
}

/// Nothing to show — and, where possible, what to do about it.
class AppEmptyState extends StatelessWidget {
  const AppEmptyState({
    required this.title,
    super.key,
    this.body,
    this.icon = Icons.inbox_outlined,
    this.action,
  });

  final String title;
  final String? body;
  final IconData icon;
  final Widget? action;

  @override
  Widget build(BuildContext context) {
    final ThemeData theme = Theme.of(context);

    return Center(
      child: Padding(
        padding: const EdgeInsets.all(32),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: <Widget>[
            Icon(icon, size: 48, color: theme.colorScheme.outline),
            const SizedBox(height: 16),
            Text(
              title,
              textAlign: TextAlign.center,
              style: theme.textTheme.titleMedium,
            ),
            if (body != null) ...<Widget>[
              const SizedBox(height: 8),
              Text(
                body!,
                textAlign: TextAlign.center,
                style: theme.textTheme.bodyMedium
                    ?.copyWith(color: theme.colorScheme.onSurfaceVariant),
              ),
            ],
            if (action != null) ...<Widget>[
              const SizedBox(height: 24),
              action!,
            ],
          ],
        ),
      ),
    );
  }
}

/// A failure, phrased for the person holding the phone.
///
/// Takes the typed [ApiException] rather than a string, because the *type* is
/// what decides whether a retry button makes sense. Offering "try again" on a
/// 403 trains people to press a button that cannot ever work.
class AppErrorState extends StatelessWidget {
  const AppErrorState({required this.error, super.key, this.onRetry});

  final Object error;
  final VoidCallback? onRetry;

  @override
  Widget build(BuildContext context) {
    final ThemeData theme = Theme.of(context);

    return Center(
      child: Padding(
        padding: const EdgeInsets.all(32),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: <Widget>[
            Icon(_icon, size: 48, color: theme.colorScheme.error),
            const SizedBox(height: 16),
            Text(
              _message,
              textAlign: TextAlign.center,
              style: theme.textTheme.bodyLarge,
            ),
            if (onRetry != null && _isRetryable) ...<Widget>[
              const SizedBox(height: 24),
              OutlinedButton.icon(
                onPressed: onRetry,
                icon: const Icon(Icons.refresh),
                label: Text(AppStrings.of(context).actionRetry),
              ),
            ],
          ],
        ),
      ),
    );
  }

  String get _message => switch (error) {
        final ApiException e => e.message,
        _ => 'Something went wrong. Please try again.',
      };

  IconData get _icon => switch (error) {
        NetworkException() => Icons.wifi_off_outlined,
        ForbiddenException() => Icons.lock_outline,
        RateLimitException() => Icons.hourglass_empty,
        _ => Icons.error_outline,
      };

  /// Retrying is offered only where it could plausibly succeed. A validation
  /// failure needs different input; a permission failure needs a different
  /// user.
  bool get _isRetryable => switch (error) {
        NetworkException() ||
        ServerException() ||
        RateLimitException() ||
        RequestInProgressException() ||
        UnexpectedException() =>
          true,
        _ => false,
      };
}
