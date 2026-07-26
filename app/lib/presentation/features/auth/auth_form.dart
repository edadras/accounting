/// The shortest check that catches a typo without rejecting a real address.
///
/// Deliberately loose: the server is the authority on whether an address is
/// deliverable, and a clever regex here only ever ends up refusing somebody's
/// legitimate mailbox.
bool emailLooksValid(String value) {
  final at = value.indexOf('@');
  if (at <= 0 || at != value.lastIndexOf('@')) return false;

  final domain = value.substring(at + 1);
  return domain.contains('.') &&
      !domain.startsWith('.') &&
      !domain.endsWith('.') &&
      !value.contains(' ');
}

/// The password length the server enforces (`min:8`). Checked here too, so a
/// too-short password costs a round trip and a confusing `validation_failed`
/// nobody can act on.
const int minimumPasswordLength = 8;
