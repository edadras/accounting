import 'dart:async';

import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/i18n/translator.dart';
import '../../../data/auth_repository.dart';
import '../../../data/finora_backend.dart';
import '../../../data/security_repository.dart';
import '../../../domain/entities.dart';

/// The auth stack, or null when the app is running on demo data.
///
/// This — and not "is there a token" — is what decides whether the app has a
/// sign-in screen at all. The default build has no server to authenticate
/// against, so it must open straight into the shell; a token that happened to
/// be lying around would not change that.
final authBackendProvider = Provider<AuthRepository?>(
  (ref) => ref.watch(finoraBackendProvider)?.auth,
);

/// Where the person is in the act of signing in.
enum AuthStage {
  /// Cold start, before the stored token has been read. Nothing is decided yet.
  restoring,
  signedOut,

  /// The password was right and the server answered with a challenge instead of
  /// a token.
  twoFactorRequired,

  /// Signed in, but `X-Workspace-Id` is still ambiguous — and nothing else
  /// works until it is not.
  choosingWorkspace,
  signedIn,
}

/// What the gate and the auth screens both read.
final class AuthState {
  const AuthState._({
    required this.stage,
    this.user,
    this.workspaces = const [],
    this.activeWorkspaceId,
    this.challenge,
    this.busy = false,
    this.error,
  });

  const AuthState.restoring() : this._(stage: AuthStage.restoring);

  const AuthState.signedOut({bool busy = false, Object? error})
      : this._(stage: AuthStage.signedOut, busy: busy, error: error);

  const AuthState.challenged(LoginChallenge challenge)
      : this._(stage: AuthStage.twoFactorRequired, challenge: challenge);

  const AuthState.choosingWorkspace({
    required List<Workspace> workspaces,
    AuthUser? user,
    bool busy = false,
    Object? error,
  }) : this._(
          stage: AuthStage.choosingWorkspace,
          workspaces: workspaces,
          user: user,
          busy: busy,
          error: error,
        );

  const AuthState.signedIn({
    AuthUser? user,
    List<Workspace> workspaces = const [],
    String? activeWorkspaceId,
  }) : this._(
          stage: AuthStage.signedIn,
          user: user,
          workspaces: workspaces,
          activeWorkspaceId: activeWorkspaceId,
        );

  final AuthStage stage;
  final AuthUser? user;
  final List<Workspace> workspaces;
  final String? activeWorkspaceId;
  final LoginChallenge? challenge;

  /// A request is in flight. Kept separate from the stage because a failed
  /// sign-in returns to exactly the screen it started from.
  final bool busy;

  /// The last failure, still in its coded form — the UI resolves it through the
  /// dictionary and never renders the server's English prose.
  final Object? error;

  bool get isSignedIn => stage == AuthStage.signedIn;
}

final authControllerProvider =
    NotifierProvider<AuthController, AuthState>(AuthController.new);

/// Drives sign-in, registration, workspace selection and sign-out.
///
/// Navigation is not its business: it publishes a stage, and the gate widget
/// decides what that looks like.
final class AuthController extends Notifier<AuthState> {
  @override
  AuthState build() {
    final auth = ref.watch(authBackendProvider);

    // No backend, no session to restore and no screen to show — the demo build
    // never reads this, but it must not throw if something does.
    if (auth == null) return const AuthState.signedIn();

    unawaited(_restore(auth));
    return const AuthState.restoring();
  }

  /// Reads the token this device kept from last time.
  ///
  /// A missing token is not a failure — it is the normal state of a fresh
  /// install, and the answer to it is the sign-in screen.
  Future<void> _restore(AuthRepository auth) async {
    try {
      if (!await auth.restore()) {
        state = const AuthState.signedOut();
        return;
      }

      // A stored workspace id makes the restore complete on its own: no request
      // has to succeed for the app to open where it was left.
      if (auth.session.workspaceId != null) {
        state = AuthState.signedIn(
          activeWorkspaceId: auth.session.workspaceId,
        );
        return;
      }

      await _finishFromServer(auth);
    } on Object catch (error) {
      state = AuthState.signedOut(error: error);
    }
  }

  Future<void> signIn({
    required String email,
    required String password,
  }) async {
    final auth = ref.read(authBackendProvider);
    if (auth == null) return;

    state = const AuthState.signedOut(busy: true);

    try {
      final response = await auth.requestLogin(
        email: email,
        password: password,
      );

      // The password was right but is not enough on its own. Nothing is adopted
      // here: the envelope carries a challenge, not a token.
      final challenge = LoginChallenge.fromLogin(response);
      if (challenge != null) {
        state = AuthState.challenged(challenge);
        return;
      }

      final result = await auth.adoptLogin(response);
      await _finish(auth, user: result.user, workspaces: result.workspaces);
    } on Object catch (error) {
      state = AuthState.signedOut(error: error);
    }
  }

  Future<void> register({
    required String name,
    required String email,
    required String password,
  }) async {
    final auth = ref.read(authBackendProvider);
    if (auth == null) return;

    state = const AuthState.signedOut(busy: true);

    try {
      final result = await auth.register(
        name: name,
        email: email,
        password: password,
        locale: ref.read(localeProvider).code,
      );
      await _finish(auth, user: result.user, workspaces: result.workspaces);
    } on Object catch (error) {
      state = AuthState.signedOut(error: error);
    }
  }

  /// The other half of a two-factor sign-in.
  ///
  /// The token was already adopted by [SecurityRepository.verifyChallenge], so
  /// all that is left is deciding which workspace this session is in.
  Future<void> completeTwoFactor(TwoFactorVerification verification) async {
    final auth = ref.read(authBackendProvider);
    if (auth == null) return;

    state = const AuthState.restoring();

    try {
      // One workspace needs no picker and, more usefully, no request: the id
      // came back with the verification.
      if (verification.workspaceIds.length == 1) {
        await auth.selectWorkspace(verification.workspaceIds.single);
        state = AuthState.signedIn(
          user: verification.user,
          activeWorkspaceId: auth.session.workspaceId,
        );
        return;
      }

      await _finishFromServer(auth, user: verification.user);
    } on Object catch (error) {
      state = AuthState.signedOut(error: error);
    }
  }

  /// The challenge died and there is nothing left to retry against.
  void restartSignIn() => state = const AuthState.signedOut();

  Future<void> selectWorkspace(String id) async {
    final auth = ref.read(authBackendProvider);
    if (auth == null) return;

    final workspaces = state.workspaces;
    final user = state.user;

    state = AuthState.choosingWorkspace(
      workspaces: workspaces,
      user: user,
      busy: true,
    );

    try {
      await auth.selectWorkspace(id);
      state = AuthState.signedIn(
        user: user,
        workspaces: workspaces,
        activeWorkspaceId: id,
      );
    } on Object catch (error) {
      state = AuthState.choosingWorkspace(
        workspaces: workspaces,
        user: user,
        error: error,
      );
    }
  }

  /// Ends the session on the server and, whatever the server says, on this
  /// device.
  Future<void> signOut() async {
    final auth = ref.read(authBackendProvider);
    if (auth == null) return;

    try {
      await auth.logout();
    } on Object {
      // logout() clears the token store before it rethrows. A server that
      // cannot be reached is no reason to keep the user signed in here.
    }

    state = const AuthState.signedOut();
  }

  /// Asks the server which workspaces this token can send, for the paths where
  /// the sign-in response did not say — a restored token, or a two-factor
  /// verification that only carried ids.
  Future<void> _finishFromServer(AuthRepository auth, {AuthUser? user}) async {
    await _finish(auth, user: user, workspaces: await auth.workspaces());
  }

  Future<void> _finish(
    AuthRepository auth, {
    required AuthUser? user,
    required List<Workspace> workspaces,
  }) async {
    if (workspaces.length > 1) {
      state = AuthState.choosingWorkspace(workspaces: workspaces, user: user);
      return;
    }

    if (workspaces.length == 1) await auth.selectWorkspace(workspaces.single.id);

    state = AuthState.signedIn(
      user: user,
      workspaces: workspaces,
      activeWorkspaceId: auth.session.workspaceId,
    );
  }
}
