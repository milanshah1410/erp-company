import 'package:flutter_test/flutter_test.dart';
import 'package:kosha_mobile/core/tenant/tenant_context.dart';

/// The payload here is the real shape of `User::sessionPayload()`.
void main() {
  Map<String, dynamic> payload({
    List<String> permissions = const <String>['sales.view', 'sales.create'],
    Object? modules = const <String>['pos', 'inventory'],
  }) =>
      <String, dynamic>{
        'id': 3,
        'name': 'Rakesh Shah',
        'email': 'rakesh@shahfootwear.test',
        'phone': '9876543210',
        'avatar_url': null,
        'roles': <String>['cashier'],
        'permissions': permissions,
        'modules': modules,
        'current_company': <String, dynamic>{
          'id': 1,
          'name': 'Shah Footwear',
          'currency_code': 'INR',
          'currency_symbol': '₹',
          'financial_year_start_month': 4,
        },
        'current_branch': <String, dynamic>{'id': 2, 'name': 'Main Counter'},
        'companies': <dynamic>[
          <String, dynamic>{'id': 1, 'name': 'Shah Footwear'},
        ],
        'branches': <dynamic>[
          <String, dynamic>{'id': 2, 'name': 'Main Counter', 'company_id': 1},
        ],
      };

  test('parses the session payload', () {
    final TenantContext ctx = TenantContext.fromJson(payload());

    expect(ctx.userId, 3);
    expect(ctx.roles, <String>['cashier']);
    expect(ctx.currentCompany?.name, 'Shah Footwear');
    expect(ctx.currentBranch?.name, 'Main Counter');
    expect(ctx.currencySymbol, '₹');
  });

  test('permission checks use the exact Spatie names', () {
    final TenantContext ctx = TenantContext.fromJson(payload());

    expect(ctx.can('sales.create'), isTrue);
    expect(ctx.can('sales.delete'), isFalse);
    expect(ctx.canAny(<String>['nope', 'sales.view']), isTrue);
  });

  test('a null module set fails OPEN, matching EnsureModuleEnabled', () {
    // A workspace provisioned before module selection existed sends null. Read
    // as "nothing is gated" — reading it as "everything is gated" would hide
    // the whole app.
    final TenantContext ctx = TenantContext.fromJson(payload(modules: null));

    expect(ctx.modules, isNull);
    expect(ctx.hasModule('anything-at-all'), isTrue);
  });

  test('an empty module set is the opposite of a null one', () {
    final TenantContext ctx =
        TenantContext.fromJson(payload(modules: const <String>[]));

    expect(ctx.hasModule('pos'), isFalse);
  });

  test('module gating honours the enabled set', () {
    final TenantContext ctx = TenantContext.fromJson(payload());

    expect(ctx.hasModule('pos'), isTrue);
    expect(ctx.hasModule('manufacturing'), isFalse);
  });

  test('no tenant id is exposed — the token carries the workspace', () {
    // Compile-time guarantee really, but written down so a future "helpful"
    // addition of a tenantId field has to delete a test that says why not.
    final TenantContext ctx = TenantContext.fromJson(payload());
    expect(
      ctx.toString().contains('tenantId'),
      isFalse,
      reason: 'the client must never hold or send a tenant id (§5)',
    );
  });
}
