/// Everything the app knows about who is signed in and what their workspace may
/// do (§5).
///
/// Built from one payload: `GET /api/me` (identical to what `POST /api/login`
/// returns under `user`). The backend has already done the hard part — the
/// `permissions` list arrives *net of* disabled modules and screens the platform
/// operator revoked, so this class does not re-derive entitlement, it reads it.
///
/// **There is no `tenantId` field, and that is deliberate.** The workspace is
/// carried by the bearer token; the client never sends, stores or chooses one.
/// Adding a tenant id here would create the very thing §5 warns against — a
/// value a user could be tempted to switch.
///
/// Immutable, and rebuilt wholesale on sign-in and on company/branch switch. A
/// mutable permission set is one that can be edited by a bug into granting
/// something.
class TenantContext {
  const TenantContext({
    required this.userId,
    required this.name,
    required this.email,
    required this.roles,
    required this.permissions,
    required this.modules,
    this.phone,
    this.avatarUrl,
    this.currentCompany,
    this.currentBranch,
    this.companies = const <CompanyRef>[],
    this.branches = const <BranchRef>[],
  });

  factory TenantContext.fromJson(Map<String, dynamic> json) => TenantContext(
        userId: (json['id'] as num).toInt(),
        name: json['name'] as String? ?? '',
        email: json['email'] as String? ?? '',
        phone: json['phone'] as String?,
        avatarUrl: json['avatar_url'] as String?,
        roles: _strings(json['roles']),
        permissions: _strings(json['permissions']).toSet(),
        // `null` means the workspace never chose a module set, which the API
        // treats as "nothing is gated". Preserved as null rather than flattened
        // to an empty set, because empty would mean the opposite: everything
        // gated off. See [hasModule].
        modules: json['modules'] == null ? null : _strings(json['modules']).toSet(),
        currentCompany: json['current_company'] == null
            ? null
            : CompanyRef.fromJson(json['current_company'] as Map<String, dynamic>),
        currentBranch: json['current_branch'] == null
            ? null
            : BranchRef.fromJson(json['current_branch'] as Map<String, dynamic>),
        companies: _list(json['companies'], CompanyRef.fromJson),
        branches: _list(json['branches'], BranchRef.fromJson),
      );

  final int userId;
  final String name;
  final String email;
  final String? phone;
  final String? avatarUrl;

  final List<String> roles;

  /// Spatie permission names, exactly as the API spells them
  /// (`sales.create`, `products.view`, …).
  ///
  /// A Set because this is asked hundreds of times while building a menu tree,
  /// and a linear scan of a 200-entry list per node is a visible cost on a
  /// low-end handset.
  final Set<String> permissions;

  /// The workspace's enabled modules, or null when module gating is off.
  final Set<String>? modules;

  final CompanyRef? currentCompany;
  final BranchRef? currentBranch;
  final List<CompanyRef> companies;
  final List<BranchRef> branches;

  /// §25: this is UX, not security.
  ///
  /// Hiding a button the user cannot use is a courtesy; Laravel refuses the call
  /// regardless, and that refusal is the actual authorization. Never treat a
  /// `true` from here as permission to skip a server check.
  bool can(String permission) => permissions.contains(permission);

  bool canAny(Iterable<String> anyOf) => anyOf.any(can);

  /// Fails **open** when [modules] is null, matching the server
  /// (`EnsureModuleEnabled` passes through when a tenant has no module set on
  /// record). Failing closed here would hide the entire app from workspaces
  /// provisioned before module selection existed.
  bool hasModule(String module) => modules == null || modules!.contains(module);

  bool get isAdmin => roles.contains('super-admin') || roles.contains('admin');

  /// For money formatting. Falls back to the Indian rupee, which is what every
  /// workspace on this platform uses today — but read from the company so it is
  /// not a lie the day one does not.
  String get currencySymbol => currentCompany?.currencySymbol ?? '₹';
  String get currencyCode => currentCompany?.currencyCode ?? 'INR';

  TenantContext copyWith({
    CompanyRef? currentCompany,
    BranchRef? currentBranch,
  }) =>
      TenantContext(
        userId: userId,
        name: name,
        email: email,
        phone: phone,
        avatarUrl: avatarUrl,
        roles: roles,
        permissions: permissions,
        modules: modules,
        currentCompany: currentCompany ?? this.currentCompany,
        currentBranch: currentBranch ?? this.currentBranch,
        companies: companies,
        branches: branches,
      );

  static List<String> _strings(dynamic value) => switch (value) {
        final List<dynamic> list =>
          list.map((dynamic e) => e.toString()).toList(growable: false),
        _ => const <String>[],
      };

  static List<T> _list<T>(
    dynamic value,
    T Function(Map<String, dynamic>) parse,
  ) =>
      switch (value) {
        final List<dynamic> list => list
            .whereType<Map<String, dynamic>>()
            .map(parse)
            .toList(growable: false),
        _ => const <Never>[],
      };
}

class CompanyRef {
  const CompanyRef({
    required this.id,
    required this.name,
    this.currencyCode,
    this.currencySymbol,
    this.financialYearStartMonth,
  });

  factory CompanyRef.fromJson(Map<String, dynamic> json) => CompanyRef(
        id: (json['id'] as num).toInt(),
        name: json['name'] as String? ?? '',
        currencyCode: json['currency_code'] as String?,
        currencySymbol: json['currency_symbol'] as String?,
        financialYearStartMonth:
            (json['financial_year_start_month'] as num?)?.toInt(),
      );

  final int id;
  final String name;
  final String? currencyCode;
  final String? currencySymbol;
  final int? financialYearStartMonth;
}

class BranchRef {
  const BranchRef({required this.id, required this.name, this.companyId});

  factory BranchRef.fromJson(Map<String, dynamic> json) => BranchRef(
        id: (json['id'] as num).toInt(),
        name: json['name'] as String? ?? '',
        companyId: (json['company_id'] as num?)?.toInt(),
      );

  final int id;
  final String name;
  final int? companyId;
}
