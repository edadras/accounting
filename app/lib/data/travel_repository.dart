import '../core/money/currency.dart';
import '../core/money/money.dart';
import '../domain/travel.dart';
import 'remote/api_client.dart';
import 'remote/api_exception.dart';
import 'remote/coded_failure.dart';
import 'remote/money_codec.dart';

final class TravelException extends CodedFailure {
  const TravelException(super.code, {super.statusCode});

  /// No server behind this build, so there are no trips to read.
  static const noBackend = 'travel_unavailable';
}

/// Trips, their members and their expenses, read from the Travel module.
///
/// `GET /trips` answers with the trip and its members but not its expenses, so
/// a trip is assembled from two calls. The split is the server's, not ours: a
/// trip's expense list is paginated and a list of trips is not.
///
/// Coordinates come straight off `SplitExpenseResource`, which exposes
/// `latitude` and `longitude` as nullable floats — the map has real data to
/// draw as soon as an expense is recorded with a position.
final class TravelRepository {
  const TravelRepository({required this.client, this.fallbackCurrency});

  final ApiClient client;

  /// Used only when the server omits a currency code, which it does not do
  /// today; carried so a malformed row degrades instead of throwing.
  final Currency? fallbackCurrency;

  Future<List<Trip>> trips({int expensesPerPage = 200}) async {
    final response = await _guard(() => client.get('/trips'));

    final trips = <Trip>[];
    for (final item in response['data'] as List? ?? const []) {
      if (item is! Map) continue;
      final json = item.cast<String, Object?>();
      final id = json['id'] as String? ?? '';
      trips.add(
        _tripFrom(
          json,
          await expenses(id, perPage: expensesPerPage),
        ),
      );
    }
    return trips;
  }

  Future<Trip> trip(String id, {int expensesPerPage = 200}) async {
    final response = await _guard(() => client.get('/trips/$id'));
    final json = (response['data'] as Map?)?.cast<String, Object?>() ?? const {};

    return _tripFrom(json, await expenses(id, perPage: expensesPerPage));
  }

  Future<List<SplitExpense>> expenses(
    String tripId, {
    int perPage = 200,
  }) async {
    final response = await _guard(
      () => client.get('/trips/$tripId/expenses', query: {'per_page': perPage}),
    );

    return [
      for (final item in response['data'] as List? ?? const [])
        if (item is Map) expenseFromJson(item.cast<String, Object?>()),
    ];
  }

  Trip _tripFrom(Map<String, Object?> json, List<SplitExpense> expenses) {
    final currency = _currency(json['base_currency'] as String?);

    return Trip(
      id: json['id'] as String? ?? '',
      name: json['name'] as String? ?? '',
      destination: json['destination'] as String? ?? '',
      startsAt: _dateOr(json['starts_at'], DateTime.fromMillisecondsSinceEpoch(0)),
      endsAt: _dateOr(json['ends_at'], DateTime.fromMillisecondsSinceEpoch(0)),
      baseCurrency: currency,
      members: [
        for (final item in json['members'] as List? ?? const [])
          if (item is Map) _memberFrom(item.cast<String, Object?>()),
      ],
      expenses: expenses,
    );
  }

  TripMember _memberFrom(Map<String, Object?> json) => TripMember(
        id: json['id'] as String? ?? '',
        name: json['display_name'] as String? ?? '',
        weight: switch (json['weight']) {
          final int value => value,
          final String value => int.tryParse(value) ?? 1,
          _ => 1,
        },
      );

  /// Public so a widget test can build an expense from a real response body
  /// without standing up the whole client.
  ///
  /// The amount taken is `base`, never `amount`: every balance on the trip
  /// screen is in the trip's base currency, and the server froze the rate at
  /// recording time precisely so this side never re-derives it.
  static SplitExpense expenseFromJson(Map<String, Object?> json) {
    final shares = [
      for (final item in json['shares'] as List? ?? const [])
        if (item is Map) item.cast<String, Object?>(),
    ];

    return SplitExpense(
      id: json['id'] as String? ?? '',
      title: json['description'] as String? ?? '',
      amount: MoneyCodec.tryDecode(json['base']) ??
          MoneyCodec.tryDecode(json['amount']) ??
          const Money(0, Currency.irr),
      payerId: json['payer_member_id'] as String? ?? '',
      participantIds: [
        for (final share in shares) share['member_id'] as String? ?? '',
      ],
      occurredAt: _dateOr(json['occurred_at'], DateTime.fromMillisecondsSinceEpoch(0)),
      // The server already allocated every share to the minor unit, so they
      // are carried across verbatim rather than re-split on this side.
      mode: shares.isEmpty ? SplitMode.equal : SplitMode.exact,
      exactShares: {
        for (final share in shares)
          (share['member_id'] as String? ?? ''):
              MoneyCodec.tryDecode(share['base'])?.minorUnits ?? 0,
      },
      latitude: _coordinate(json['latitude']),
      longitude: _coordinate(json['longitude']),
    );
  }

  /// `decimal(10,7)` reaches the client as a JSON number, but a driver that
  /// keeps decimals as strings would send `"41.0082"` — both are accepted, and
  /// anything else places nothing rather than placing a marker at the equator.
  static double? _coordinate(Object? raw) => switch (raw) {
        final double value => value,
        final int value => value.toDouble(),
        final String value => double.tryParse(value.trim()),
        _ => null,
      };

  static DateTime _dateOr(Object? raw, DateTime fallback) =>
      raw is String ? DateTime.tryParse(raw)?.toLocal() ?? fallback : fallback;

  Currency _currency(String? code) {
    if (code == null || code.isEmpty) return fallbackCurrency ?? Currency.irr;
    return Currency.byCode(code) ??
        fallbackCurrency ??
        Currency(code: code.toUpperCase(), symbol: code.toUpperCase(), minorUnit: 2);
  }

  static Future<T> _guard<T>(Future<T> Function() call) async {
    try {
      return await call();
    } on ApiException catch (error) {
      throw TravelException(error.code, statusCode: error.statusCode);
    }
  }
}
