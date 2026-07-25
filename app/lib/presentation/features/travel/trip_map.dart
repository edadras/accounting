import 'dart:math' as math;

import 'package:flutter/material.dart';

import '../../../core/theme/neon_effects.dart';
import '../../../core/theme/neon_palette.dart';
import '../../../domain/travel.dart';

/// One expense, placed.
final class TripMapMarker {
  const TripMapMarker({
    required this.expense,
    required this.position,
    required this.radius,
    required this.isLargest,
  });

  final SplitExpense expense;

  /// Where the marker sits inside the plot, in logical pixels.
  final Offset position;
  final double radius;

  /// The single biggest amount on this map. Nothing is the largest when every
  /// expense costs the same — there would be nothing to point at.
  final bool isLargest;
}

/// The result of turning a trip's coordinates into a drawable plot.
final class TripMapLayout {
  const TripMapLayout({
    required this.markers,
    required this.placed,
    required this.unplaced,
    required this.isSinglePoint,
  });

  final List<TripMapMarker> markers;

  /// Expenses that carried a latitude *and* a longitude.
  final int placed;

  /// Expenses that carried neither, or only one half of a pair.
  final int unplaced;

  /// Every placed expense sits at the identical coordinate. The plot cannot
  /// say anything about *where* in that case, only *that*, so the screen says
  /// it in words instead of implying a spread that does not exist.
  final bool isSinglePoint;

  bool get isEmpty => markers.isEmpty;
}

/// Projects coordinates into a bounded box.
///
/// No basemap is involved and none is implied: this is a scatter plot of
/// latitude against longitude inside a frame, which is an honest thing to draw
/// without a tile server. Longitude runs east across the box and latitude runs
/// north up it, so the shape of a trip is recognisable even though the
/// distances are not to scale.
abstract final class TripMapProjection {
  /// Below this a span is treated as no span at all. Coordinates are stored as
  /// `decimal(10,7)`, so anything smaller than a ten-millionth of a degree is
  /// the same point as far as the database is concerned.
  static const double epsilon = 1e-7;

  static TripMapLayout layout(
    List<SplitExpense> expenses, {
    required Size size,
    double padding = 30,
    double minRadius = 7,
    double maxRadius = 20,
  }) {
    final placed = [
      for (final expense in expenses)
        if (expense.hasLocation) expense,
    ];
    final unplaced = expenses.length - placed.length;

    if (placed.isEmpty) {
      return TripMapLayout(
        markers: const [],
        placed: 0,
        unplaced: unplaced,
        isSinglePoint: false,
      );
    }

    var minLat = placed.first.latitude!;
    var maxLat = minLat;
    var minLng = placed.first.longitude!;
    var maxLng = minLng;
    var minAmount = placed.first.amount.minorUnits;
    var maxAmount = minAmount;

    for (final expense in placed) {
      minLat = math.min(minLat, expense.latitude!);
      maxLat = math.max(maxLat, expense.latitude!);
      minLng = math.min(minLng, expense.longitude!);
      maxLng = math.max(maxLng, expense.longitude!);
      minAmount = math.min(minAmount, expense.amount.minorUnits);
      maxAmount = math.max(maxAmount, expense.amount.minorUnits);
    }

    final latSpan = maxLat - minLat;
    final lngSpan = maxLng - minLng;
    final amountSpan = maxAmount - minAmount;

    // Each axis is collapsed on its own. A trip along one meridian has a real
    // north–south spread and a zero east–west one, and only the second axis
    // should be flattened to the middle.
    final flatLat = latSpan.abs() < epsilon;
    final flatLng = lngSpan.abs() < epsilon;
    final singlePoint = flatLat && flatLng;

    final width = math.max(0.0, size.width - padding * 2);
    final height = math.max(0.0, size.height - padding * 2);

    final markers = <TripMapMarker>[];
    for (var i = 0; i < placed.length; i++) {
      final expense = placed[i];

      final fx = flatLng ? 0.5 : (expense.longitude! - minLng) / lngSpan;
      // North is up, so a bigger latitude is a smaller y.
      final fy = flatLat ? 0.5 : 1 - (expense.latitude! - minLat) / latSpan;

      var dx = padding + fx * width;
      var dy = padding + fy * height;

      // Coincident markers are fanned onto a small ring around the shared
      // point. They would otherwise stack into one blob with a single reachable
      // tap target; the legend says outright that they share a location, so the
      // ring reads as a disclosure and not as a spread.
      if (singlePoint && placed.length > 1) {
        final angle = 2 * math.pi * i / placed.length - math.pi / 2;
        final ring = math.min(width, height) * 0.18;
        dx += math.cos(angle) * ring;
        dy += math.sin(angle) * ring;
      }

      final weight = amountSpan == 0
          ? 0.5
          : (expense.amount.minorUnits - minAmount) / amountSpan;

      markers.add(TripMapMarker(
        expense: expense,
        position: Offset(dx, dy),
        radius: minRadius + (maxRadius - minRadius) * weight,
        isLargest: amountSpan > 0 && expense.amount.minorUnits == maxAmount,
      ),);
    }

    return TripMapLayout(
      markers: markers,
      placed: placed.length,
      unplaced: unplaced,
      isSinglePoint: singlePoint,
    );
  }
}

/// Draws the frame, the graticule and the markers.
///
/// The frame and the grid are hairlines and never glow: they are scaffolding,
/// and scaffolding that glows says "look here" about nothing. The one marker
/// that glows is the largest expense, which is the single fact this plot is
/// worth reading for.
class TripMapPainter extends CustomPainter {
  const TripMapPainter({
    required this.markers,
    required this.selectedId,
    required this.isDark,
  });

  final List<TripMapMarker> markers;
  final String? selectedId;
  final bool isDark;

  static const _grid = 4;

  @override
  void paint(Canvas canvas, Size size) {
    final frame = Paint()
      ..style = PaintingStyle.stroke
      ..strokeWidth = 1
      ..color = (isDark ? NeonPalette.cyan : NeonPalette.lightCyan)
          .withValues(alpha: isDark ? 0.16 : 0.20);

    final grid = Paint()
      ..style = PaintingStyle.stroke
      ..strokeWidth = 0.5
      ..color = (isDark ? NeonPalette.cyan : NeonPalette.lightCyan)
          .withValues(alpha: isDark ? 0.07 : 0.10);

    canvas.drawRRect(
      RRect.fromRectAndRadius(
        Offset.zero & size,
        const Radius.circular(NeonEffects.radiusMd),
      ),
      frame,
    );

    for (var i = 1; i < _grid; i++) {
      final x = size.width * i / _grid;
      final y = size.height * i / _grid;
      canvas.drawLine(Offset(x, 0), Offset(x, size.height), grid);
      canvas.drawLine(Offset(0, y), Offset(size.width, y), grid);
    }

    for (final marker in markers) {
      final accent = marker.isLargest
          ? NeonPalette.amber
          : (isDark ? NeonPalette.cyan : NeonPalette.lightCyan);

      if (marker.isLargest && isDark) {
        canvas.drawCircle(
          marker.position,
          marker.radius,
          Paint()
            ..color = accent.withValues(alpha: 0.55)
            ..maskFilter = const MaskFilter.blur(BlurStyle.normal, 10),
        );
      }

      canvas.drawCircle(
        marker.position,
        marker.radius,
        Paint()..color = accent.withValues(alpha: isDark ? 0.26 : 0.18),
      );
      canvas.drawCircle(
        marker.position,
        marker.radius,
        Paint()
          ..style = PaintingStyle.stroke
          ..strokeWidth = 1.6
          ..color = accent,
      );

      if (marker.expense.id == selectedId) {
        canvas.drawCircle(
          marker.position,
          marker.radius + 6,
          Paint()
            ..style = PaintingStyle.stroke
            ..strokeWidth = 1.5
            ..color = NeonPalette.violet,
        );
      }
    }
  }

  @override
  bool shouldRepaint(TripMapPainter old) =>
      old.selectedId != selectedId ||
      old.isDark != isDark ||
      old.markers != markers;
}

/// The plot plus one transparent tap target per marker.
///
/// Painting stays in the painter; the targets are real widgets so each marker
/// is independently reachable and independently announced to a screen reader,
/// which a canvas alone cannot be.
///
/// The plot is not mirrored in Arabic or Persian. Direction here is a compass,
/// not a reading order: flipping it would move east to the left and silently
/// misplace every expense.
class TripMapPlot extends StatelessWidget {
  const TripMapPlot({
    super.key,
    required this.expenses,
    required this.selectedId,
    required this.onSelect,
    required this.semanticsLabel,
    this.height = 260,
  });

  final List<SplitExpense> expenses;
  final String? selectedId;
  final void Function(SplitExpense expense) onSelect;

  /// Spoken name for one marker, built by the caller so the amount is
  /// formatted in the reader's own digits.
  final String Function(SplitExpense expense) semanticsLabel;

  final double height;

  @override
  Widget build(BuildContext context) {
    final isDark = Theme.of(context).brightness == Brightness.dark;

    return SizedBox(
      height: height,
      child: Directionality(
        textDirection: TextDirection.ltr,
        child: LayoutBuilder(
          builder: (context, constraints) {
            final size = Size(constraints.maxWidth, height);
            final layout = TripMapProjection.layout(expenses, size: size);

            return Stack(
              children: [
                Positioned.fill(
                  child: CustomPaint(
                    painter: TripMapPainter(
                      markers: layout.markers,
                      selectedId: selectedId,
                      isDark: isDark,
                    ),
                  ),
                ),
                for (final marker in layout.markers)
                  Positioned(
                    left: marker.position.dx - 22,
                    top: marker.position.dy - 22,
                    width: 44,
                    height: 44,
                    child: Semantics(
                      button: true,
                      selected: marker.expense.id == selectedId,
                      label: semanticsLabel(marker.expense),
                      child: GestureDetector(
                        key: ValueKey('trip-map-marker-${marker.expense.id}'),
                        behavior: HitTestBehavior.opaque,
                        onTap: () => onSelect(marker.expense),
                        child: const SizedBox.expand(),
                      ),
                    ),
                  ),
              ],
            );
          },
        ),
      ),
    );
  }
}
