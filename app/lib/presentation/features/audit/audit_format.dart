import '../../../core/date/date_formatter.dart';
import '../../../core/i18n/app_locale.dart';
import '../../../core/i18n/translator.dart';
import '../../../data/members_repository.dart';
import '../ai/ai_format.dart';

/// Turning stored trail rows into something a person reads.
abstract final class AuditFormat {
  /// Date plus time. The trail is scanned for "when exactly", so the clock time
  /// matters here in a way it does not on a transaction row.
  static String timestamp(DateTime? at, AppLocale locale) {
    if (at == null) return '';

    final clock = '${at.hour.toString().padLeft(2, '0')}:'
        '${at.minute.toString().padLeft(2, '0')}';

    return '${DateFormatter.short(at, locale)} '
        '${AiFormat.digits(clock, locale.code)}';
  }

  /// `auth.login_failed` → the sentence for it, or the raw action when this
  /// build has never heard of it. A missing key must not render as
  /// `audit.action.something_new` on a user's screen.
  static String action(Translator t, String action) {
    final key = 'audit.action.${action.replaceAll('.', '_')}';
    final label = t(key);
    return label == key ? action : label;
  }

  /// Field names arrive from the server in snake_case. Known ones are
  /// translated; the rest are shown as-is rather than guessed at.
  static String field(Translator t, String field) {
    final key = 'audit.field.$field';
    final label = t(key);
    return label == key ? field.replaceAll('_', ' ') : label;
  }

  /// Values are raw JSON. Two of them — a role and an invitation status — have
  /// words in the app already, and reading "Admin" beats reading "admin".
  static String value(
    Translator t,
    String field,
    Object? raw,
    String localeCode,
  ) {
    if (raw == null || (raw is String && raw.isEmpty)) {
      return t('audit.emptyValue');
    }

    if (field == 'role' && raw is String) {
      final role = WorkspaceRole.values.where((r) => r.name == raw);
      if (role.isNotEmpty) {
        return t(switch (role.first) {
          WorkspaceRole.owner => 'members.roleOwner',
          WorkspaceRole.admin => 'members.roleAdmin',
          WorkspaceRole.accountant => 'members.roleAccountant',
          WorkspaceRole.member => 'members.roleMember',
          WorkspaceRole.viewer => 'members.roleViewer',
        },);
      }
    }

    // Numbers follow the locale's digit shape; strings do not, because an
    // identifier or an email address with Persian numerals in it is no longer
    // the value the server stored.
    if (raw is num) return AiFormat.digits(raw.toString(), localeCode);

    return '$raw';
  }
}
