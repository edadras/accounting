import 'dart:typed_data';

import 'package:dio/dio.dart';

import 'remote/api_client.dart';
import 'remote/api_exception.dart';
import 'remote/coded_failure.dart';

/// What the app is able to do with a stored file, derived from its media type.
///
/// Deliberately coarser than `documents.kind` on the server: `receipt`,
/// `invoice` and `contract` all say what a file *means*, and none of them say
/// how to put it on screen. This does.
enum DocumentMedia {
  /// Decodable by the Flutter engine itself — the only kind this app can
  /// actually render.
  image,
  pdf,
  audio,
  video,

  /// Word, Excel, plain text, CSV.
  document,
  archive,
  other;

  /// Whether the app can show the file itself rather than a description of it.
  ///
  /// True for exactly one value, and that is the honest answer: rendering a
  /// PDF, decoding an audio stream or playing video are all package-sized
  /// problems, and pretending otherwise would put a play button on screen that
  /// does nothing.
  bool get isRenderable => this == DocumentMedia.image;

  static DocumentMedia forMime(String? mime) {
    final type = (mime ?? '').toLowerCase();

    return switch (type) {
      _ when type.startsWith('image/') => DocumentMedia.image,
      'application/pdf' => DocumentMedia.pdf,
      _ when type.startsWith('audio/') => DocumentMedia.audio,
      _ when type.startsWith('video/') => DocumentMedia.video,
      'text/plain' || 'text/csv' => DocumentMedia.document,
      _ when type.startsWith('application/msword') => DocumentMedia.document,
      _ when type.startsWith('application/vnd.ms-') => DocumentMedia.document,
      _ when type.startsWith('application/vnd.openxmlformats-') =>
        DocumentMedia.document,
      'application/zip' ||
      'application/x-zip-compressed' ||
      'application/x-rar-compressed' ||
      'application/x-7z-compressed' ||
      'application/gzip' =>
        DocumentMedia.archive,
      _ => DocumentMedia.other,
    };
  }
}

/// One row of `documents`, exactly as `DocumentResource` presents it.
///
/// Note what is *not* here: a path and a URL. The resource exposes neither, so
/// nothing on this side can invent a location for the blob — see
/// [DocumentsRepository.contentUrl].
final class DocumentFile {
  const DocumentFile({
    required this.id,
    required this.originalName,
    required this.mime,
    required this.size,
    required this.kind,
    this.checksum,
    this.ocrStatus,
    this.ocrText,
    this.createdAt,
  });

  final String id;
  final String originalName;
  final String? mime;

  /// Bytes. The server stores it as an integer and so does this.
  final int size;

  /// The server's `kind` — `receipt`, `invoice`, `photo`, … Kept as a raw
  /// string because a build that has not heard of a new kind must still list
  /// the document rather than fail to decode it.
  final String kind;
  final String? checksum;
  final String? ocrStatus;
  final String? ocrText;
  final DateTime? createdAt;

  DocumentMedia get media => DocumentMedia.forMime(mime);

  bool get hasText => (ocrText ?? '').trim().isNotEmpty;

  static DocumentFile fromJson(Map<String, Object?> json) {
    final ocr = (json['ocr'] as Map?)?.cast<String, Object?>() ?? const {};

    return DocumentFile(
      id: json['id'] as String? ?? '',
      originalName: json['original_name'] as String? ?? '',
      mime: json['mime'] as String?,
      size: switch (json['size']) {
        final int value => value,
        final String value => int.tryParse(value) ?? 0,
        _ => 0,
      },
      kind: json['kind'] as String? ?? 'other',
      checksum: json['checksum'] as String?,
      ocrStatus: ocr['status'] as String?,
      ocrText: ocr['text'] as String?,
      createdAt: switch (json['created_at']) {
        final String raw => DateTime.tryParse(raw)?.toLocal(),
        _ => null,
      },
    );
  }
}

final class DocumentException extends CodedFailure {
  const DocumentException(super.code, {super.statusCode});

  /// No server behind this build, so there is nothing stored to look at.
  static const noBackend = 'documents_unavailable';

  /// The metadata is readable but the bytes are not: today `GET
  /// documents/{id}/download` is not a route the Documents module registers.
  static const contentUnavailable = 'document_content_unavailable';

  bool get isContentUnavailable => code == contentUnavailable;
}

/// The Documents module, as the app sees it.
///
/// Every method here maps onto a route that exists — `GET documents`, `GET
/// documents/{id}` — with one deliberate exception, [content], which is
/// documented below. Attaching and detaching are the record screens' business
/// and are not modelled here.
final class DocumentsRepository {
  const DocumentsRepository({required this.client});

  final ApiClient client;

  Future<List<DocumentFile>> documents({
    String? kind,
    String? attachedType,
    String? attachedId,
    int perPage = 50,
  }) async {
    final response = await _guard(
      () => client.get('/documents', query: {
        if (kind != null) 'kind': kind,
        if (attachedType != null) 'attached_type': attachedType,
        if (attachedId != null) 'attached_id': attachedId,
        'per_page': perPage,
      },),
    );

    return [
      for (final item in response['data'] as List? ?? const [])
        if (item is Map) DocumentFile.fromJson(item.cast<String, Object?>()),
    ];
  }

  Future<DocumentFile> document(String id) async {
    final response = await _guard(() => client.get('/documents/$id'));

    return DocumentFile.fromJson(
      (response['data'] as Map?)?.cast<String, Object?>() ?? const {},
    );
  }

  /// The absolute address of the blob, for the "open it somewhere else"
  /// affordance.
  ///
  /// Built from the client's own base URL rather than read off the payload,
  /// because `DocumentResource` returns no URL of any kind — not a path, not a
  /// signed link. docs/02-modules.md §11 says the blob will be reachable by a
  /// short-lived signed URL; until the module issues one, this is the
  /// conventional address and nothing more.
  String contentUrl(String id) {
    final base = client.dio.options.baseUrl;
    final trimmed = base.endsWith('/') ? base.substring(0, base.length - 1) : base;
    return '$trimmed/documents/$id/download';
  }

  /// The file's bytes.
  ///
  /// **This route does not exist yet.** The Documents module registers index,
  /// store, show, destroy, attach and detach and nothing that streams the blob
  /// — verified against `php artisan route:list`. Reports and DataOps both
  /// already stream through `.../{id}/download`, so that is the shape assumed
  /// here, and until Documents grows the same route every call lands on a 404
  /// and surfaces as [DocumentException.contentUnavailable]. The preview says
  /// so in words instead of showing a broken image.
  Future<Uint8List> content(String id) async {
    try {
      final response = await client.dio.get<List<int>>(
        '/documents/$id/download',
        options: Options(responseType: ResponseType.bytes),
      );
      return Uint8List.fromList(response.data ?? const []);
    } on DioException catch (error) {
      final failure = ApiException.fromDio(error);
      if (failure.statusCode == 404 || failure.statusCode == 405) {
        throw const DocumentException(
          DocumentException.contentUnavailable,
          statusCode: 404,
        );
      }
      throw DocumentException(failure.code, statusCode: failure.statusCode);
    }
  }

  static Future<T> _guard<T>(Future<T> Function() call) async {
    try {
      return await call();
    } on ApiException catch (error) {
      throw DocumentException(error.code, statusCode: error.statusCode);
    }
  }
}
