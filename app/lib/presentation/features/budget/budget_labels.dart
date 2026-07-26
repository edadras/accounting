import '../../../core/i18n/translator.dart';
import '../../../data/budget_repository.dart';
import '../../../domain/analytics.dart' show BudgetPeriod;

/// The words for a budget's scope and period, in one place so the list and the
/// form can never disagree about what a budget is called.

String budgetScopeLabel(Translator t, BudgetScope scope) =>
    t(switch (scope) {
      BudgetScope.overall => 'budget.scopeOverall',
      BudgetScope.category => 'budget.scopeCategory',
      BudgetScope.project => 'budget.scopeProject',
      BudgetScope.trip => 'budget.scopeTrip',
      BudgetScope.building => 'budget.scopeBuilding',
      BudgetScope.member => 'budget.scopeMember',
    },);

String budgetPeriodLabel(Translator t, BudgetPeriod period) =>
    t(switch (period) {
      BudgetPeriod.monthly => 'budget.periodMonthly',
      BudgetPeriod.yearly => 'budget.periodYearly',
      BudgetPeriod.custom => 'budget.periodCustom',
    },);

/// The scopes the form can actually target.
///
/// The other four point at a project, a trip, a building or a member, and this
/// screen has no picker for any of them — offering the scope without a way to
/// name the thing it covers would only produce a budget the server rejects.
const budgetFormScopes = [BudgetScope.overall, BudgetScope.category];
