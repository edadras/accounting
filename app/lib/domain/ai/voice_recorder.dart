/// Where a captured utterance ends up.
final class VoiceCapture {
  const VoiceCapture({required this.transcript, required this.duration});

  final String transcript;
  final Duration duration;
}

/// The microphone, as the AI feature sees it.
///
/// Recording and speech-to-text need a native plugin and a real device, neither
/// of which this build has. Modelling the capture as a port keeps the *flow* —
/// idle, recording, transcript, correction, draft — fully implemented and fully
/// testable now; the platform implementation later has one small surface to
/// satisfy and nothing above it changes.
abstract interface class VoiceRecorder {
  bool get isRecording;

  Future<void> start();

  /// Stops recording and returns what was heard. The transcript is shown to the
  /// user for correction before it is parsed — speech recognition is wrong
  /// often enough that silently trusting it would put bad numbers in a ledger.
  Future<VoiceCapture> stopAndTranscribe();

  Future<void> cancel();
}

/// A recorder that hears one scripted sentence.
///
/// The sentence is sample *input data*, not UI copy — it stands in for what the
/// user said, so it is not a translation key.
final class FakeVoiceRecorder implements VoiceRecorder {
  FakeVoiceRecorder({
    this.transcript = 'دیروز ۳۵۰ لیر برای شام پرداخت کردم',
    this.latency = Duration.zero,
  });

  final String transcript;
  final Duration latency;

  bool _recording = false;
  DateTime? _startedAt;

  @override
  bool get isRecording => _recording;

  @override
  Future<void> start() async {
    _recording = true;
    _startedAt = DateTime.now();
  }

  @override
  Future<VoiceCapture> stopAndTranscribe() async {
    if (latency > Duration.zero) await Future<void>.delayed(latency);
    final started = _startedAt;
    _recording = false;
    _startedAt = null;
    return VoiceCapture(
      transcript: transcript,
      duration: started == null
          ? Duration.zero
          : DateTime.now().difference(started),
    );
  }

  @override
  Future<void> cancel() async {
    _recording = false;
    _startedAt = null;
  }
}
