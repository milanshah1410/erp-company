import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

/// The app's text input (§27).
///
/// Wraps `TextFormField` for one reason worth the file: [serverError]. Laravel
/// returns validation failures field by field (`{errors: {email: [...]}}`), and
/// this is what puts each message under the input it belongs to instead of in a
/// banner that makes the user hunt for which field was wrong.
class AppTextField extends StatelessWidget {
  const AppTextField({
    required this.controller,
    required this.label,
    super.key,
    this.hint,
    this.obscure = false,
    this.enabled = true,
    this.keyboardType,
    this.textInputAction,
    this.autofillHints,
    this.prefixIcon,
    this.suffixIcon,
    this.serverError,
    this.validator,
    this.onSubmitted,
    this.inputFormatters,
    this.maxLines = 1,
    this.autofocus = false,
  });

  final TextEditingController controller;
  final String label;
  final String? hint;
  final bool obscure;
  final bool enabled;
  final TextInputType? keyboardType;
  final TextInputAction? textInputAction;
  final List<String>? autofillHints;
  final Widget? prefixIcon;
  final Widget? suffixIcon;

  /// A message from the API's `errors` map for this field. Shown as the field's
  /// error until the user edits, at which point the caller should clear it —
  /// a server error that outlives the value it referred to is a lie.
  final String? serverError;

  final String? Function(String?)? validator;
  final ValueChanged<String>? onSubmitted;
  final List<TextInputFormatter>? inputFormatters;
  final int maxLines;
  final bool autofocus;

  @override
  Widget build(BuildContext context) => TextFormField(
        controller: controller,
        obscureText: obscure,
        enabled: enabled,
        keyboardType: keyboardType,
        textInputAction: textInputAction,
        autofillHints: autofillHints,
        inputFormatters: inputFormatters,
        maxLines: obscure ? 1 : maxLines,
        autofocus: autofocus,
        onFieldSubmitted: onSubmitted,
        // Validate as the user fixes things, not on every keystroke from the
        // start: flagging "invalid email" while somebody is still typing the
        // first character is noise.
        autovalidateMode: AutovalidateMode.onUserInteraction,
        validator: (String? value) => validator?.call(value) ?? serverError,
        decoration: InputDecoration(
          labelText: label,
          hintText: hint,
          prefixIcon: prefixIcon,
          suffixIcon: suffixIcon,
          errorText: serverError,
          // Two lines: Gujarati and Hindi messages run longer than English and
          // a single-line error box clips them.
          errorMaxLines: 2,
        ),
      );
}

/// A debounced search box for list screens.
///
/// The debounce is not cosmetic: §17 requires server-side filtering, so every
/// keystroke would otherwise be a round trip, and on a 3G connection at a shop
/// counter that is both slow and expensive.
class AppSearchField extends StatefulWidget {
  const AppSearchField({
    required this.onChanged,
    super.key,
    this.hint = 'Search',
    this.debounce = const Duration(milliseconds: 350),
    this.initialValue,
  });

  final ValueChanged<String> onChanged;
  final String hint;
  final Duration debounce;
  final String? initialValue;

  @override
  State<AppSearchField> createState() => _AppSearchFieldState();
}

class _AppSearchFieldState extends State<AppSearchField> {
  late final TextEditingController _controller =
      TextEditingController(text: widget.initialValue);
  Object? _pending;

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  void _onChanged(String value) {
    // A token per keystroke; only the last one survives the delay, so an
    // in-flight debounce from an earlier character cannot fire late and
    // overwrite a newer query.
    final Object token = Object();
    _pending = token;

    Future<void>.delayed(widget.debounce, () {
      if (mounted && identical(_pending, token)) {
        widget.onChanged(value.trim());
      }
    });
  }

  @override
  Widget build(BuildContext context) => TextField(
        controller: _controller,
        textInputAction: TextInputAction.search,
        onChanged: _onChanged,
        decoration: InputDecoration(
          hintText: widget.hint,
          prefixIcon: const Icon(Icons.search),
          suffixIcon: _controller.text.isEmpty
              ? null
              : IconButton(
                  icon: const Icon(Icons.close),
                  onPressed: () {
                    _controller.clear();
                    _pending = null;
                    widget.onChanged('');
                    setState(() {});
                  },
                ),
        ),
      );
}
