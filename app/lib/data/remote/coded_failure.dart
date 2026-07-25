import 'api_exception.dart';

/// A refusal from the server, reduced to the one thing the UI may act on: the
/// stable machine-readable `code`.
///
/// The server's `message` is deliberately dropped rather than carried. A field
/// that exists is a field that eventually gets rendered, and the server writes
/// its refusals in English — putting one on a Persian screen is the failure
/// mode this class exists to make impossible.
abstract class CodedFailure implements Exception {
  const CodedFailure(this.code, {this.statusCode});

  /// Stable, English, machine-readable — never shown to anyone.
  final String code;
  final int? statusCode;

  /// The i18n key the UI renders. Shares the `error.` namespace with
  /// [ApiException] so a transport failure and a domain refusal read the same.
  String get translationKey => 'error.$code';

  /// A bare `abort(403)` carries no envelope, so it arrives as this code. It
  /// means "your role is not enough", which deserves an explanation rather than
  /// the same red box as a typo in an email address.
  bool get isPermissionDenied =>
      code == 'forbidden' || code == 'workspace_forbidden';

  bool get isOffline =>
      code == ApiException.codeNetworkUnreachable ||
      code == ApiException.codeNetworkTimeout;

  @override
  String toString() => '$runtimeType($code, status: $statusCode)';
}
