import { createRouter, createWebHistory, type RouteRecordRaw } from 'vue-router'
import { useAuthStore } from '../stores/auth'
import { useMenuStore } from '../stores/menu'
import { usePortalAuthStore } from '../stores/portalAuth'
import { useCentralAuthStore } from '../stores/centralAuth'
import { useRouteProgress } from '../composables/useRouteProgress'
import AppLayout from '../layouts/AppLayout.vue'
import PortalLayout from '../layouts/PortalLayout.vue'
import CentralAdminLayout from '../layouts/CentralAdminLayout.vue'
import LoginView from '../views/LoginView.vue'

// Route-level code splitting (§17 Performance — Lazy Loading).
//
// Only the app shell (layouts + the primary staff LoginView, the most common
// entry point) is bundled into the initial chunk. Every feature view below is a
// dynamic `() => import()` so Vite emits it as a separate chunk that is fetched
// on first navigation and cached thereafter — turning one ~130-view monolith
// bundle into ~130 small on-demand chunks. Route definitions are unchanged; they
// reference these lazy factories exactly as before.
const CentralLoginView = () => import('../views/central/CentralLoginView.vue')
const CentralDashboardView = () => import('../views/central/CentralDashboardView.vue')
const CentralOperatorsView = () => import('../views/central/CentralOperatorsView.vue')
const CentralRolesView = () => import('../views/central/CentralRolesView.vue')
const CentralTenantsView = () => import('../views/central/CentralTenantsView.vue')
const CentralIndustriesView = () => import('../views/central/CentralIndustriesView.vue')
const CentralMenusView = () => import('../views/central/CentralMenusView.vue')
const CentralShopMenusView = () => import('../views/central/CentralShopMenusView.vue')
const CentralPlansView = () => import('../views/central/CentralPlansView.vue')
const CentralBillingView = () => import('../views/central/CentralBillingView.vue')
const CentralMarketplaceView = () => import('../views/central/CentralMarketplaceView.vue')
const CentralUsageView = () => import('../views/central/CentralUsageView.vue')
const CentralAnalyticsView = () => import('../views/central/CentralAnalyticsView.vue')
const CentralInsightsView = () => import('../views/central/CentralInsightsView.vue')
const CentralOpsView = () => import('../views/central/CentralOpsView.vue')
const CentralSupportView = () => import('../views/central/CentralSupportView.vue')
const PortalLoginView = () => import('../views/portal/PortalLoginView.vue')
const PortalCatalogueView = () => import('../views/portal/PortalCatalogueView.vue')
const PortalCartView = () => import('../views/portal/PortalCartView.vue')
const PortalOrdersView = () => import('../views/portal/PortalOrdersView.vue')
const PortalPriceListView = () => import('../views/portal/PortalPriceListView.vue')
const CentralRegisterView = () => import('../views/central/CentralRegisterView.vue')
const CentralVerifyView = () => import('../views/central/CentralVerifyView.vue')
const CentralProvisioningView = () => import('../views/central/CentralProvisioningView.vue')
const BillingView = () => import('../views/BillingView.vue')
const SuspendedView = () => import('../views/SuspendedView.vue')
const AccessDeniedView = () => import('../views/AccessDeniedView.vue')
const DashboardView = () => import('../views/DashboardView.vue')
const CrudView = () => import('../views/CrudView.vue')
const CategoriesView = () => import('../views/CategoriesView.vue')
const ProductsView = () => import('../views/ProductsView.vue')
const PurchaseIndentsView = () => import('../views/PurchaseIndentsView.vue')
const RfqsView = () => import('../views/RfqsView.vue')
const PurchaseOrdersView = () => import('../views/PurchaseOrdersView.vue')
const VendorRatingsView = () => import('../views/VendorRatingsView.vue')
const PurchaseInvoicesView = () => import('../views/PurchaseInvoicesView.vue')
const StockView = () => import('../views/StockView.vue')
const StockLedgerView = () => import('../views/StockLedgerView.vue')
const StockAdjustmentsView = () => import('../views/StockAdjustmentsView.vue')
const StockTransfersView = () => import('../views/StockTransfersView.vue')
const StockReturnsView = () => import('../views/StockReturnsView.vue')
const GateEntriesView = () => import('../views/GateEntriesView.vue')
const UsersView = () => import('../views/UsersView.vue')
const RolesView = () => import('../views/RolesView.vue')
const SettingsView = () => import('../views/SettingsView.vue')
const WarehousesView = () => import('../views/WarehousesView.vue')
const SuppliersView = () => import('../views/SuppliersView.vue')
const CustomersView = () => import('../views/CustomersView.vue')
const BrokerLedgerView = () => import('../views/BrokerLedgerView.vue')
const TailorWorkView = () => import('../views/TailorWorkView.vue')
const TailorPaymentView = () => import('../views/TailorPaymentView.vue')
const TailorLedgerView = () => import('../views/TailorLedgerView.vue')
const TailorBonusView = () => import('../views/TailorBonusView.vue')
const TailorPerformanceView = () => import('../views/TailorPerformanceView.vue')
const EmbroideryWorkView = () => import('../views/EmbroideryWorkView.vue')
const EmbroideryPaymentView = () => import('../views/EmbroideryPaymentView.vue')
const EmbroideryLedgerView = () => import('../views/EmbroideryLedgerView.vue')
const CustomerCategoriesView = () => import('../views/CustomerCategoriesView.vue')
const LeadsView = () => import('../views/LeadsView.vue')
const FollowUpsView = () => import('../views/FollowUpsView.vue')
const ReferralsView = () => import('../views/ReferralsView.vue')
const LoyaltyView = () => import('../views/LoyaltyView.vue')
const ServiceJobsView = () => import('../views/ServiceJobsView.vue')
const ServiceContractsView = () => import('../views/ServiceContractsView.vue')
/**
 * Retail industry plugin (app/Modules/Industry/Retail) — the garment trade.
 *
 * Only two screens are here. The masters behind them (styles, fabrics, size
 * runs, colours) are plain CRUD and are driven from config/resources.ts through
 * the shared CrudView, and "Alterations" is a second door onto the existing
 * /tailor-alterations screen — a retail alteration is a tailor alteration with a
 * customer on it, not a second register.
 */
const GarmentMatrixView = () => import('../views/GarmentMatrixView.vue')
const GarmentTrialsView = () => import('../views/GarmentTrialsView.vue')
// Gift vouchers are a Loyalty & Offers feature living in core, not a garment
// one: a gift shop and a supermarket sell the same instrument, and the till
// redeems it.
const GiftVouchersView = () => import('../views/GiftVouchersView.vue')
/**
 * Gift & Novelty industry plugin (app/Modules/Industry/Gift). Three screens: the
 * wrap desk (a queue), hamper packs (design + assemble) and the occasion board.
 *
 * The wrapping *master* has no component of its own — it is a flat master and
 * runs on the generic CrudView, declared as the `gift-wrap-options` resource.
 * Gift vouchers are core's, above.
 */
const GiftWrapDeskView = () => import('../views/GiftWrapDeskView.vue')
const GiftPacksView = () => import('../views/GiftPacksView.vue')
const GiftOccasionsView = () => import('../views/GiftOccasionsView.vue')
/**
 * Toys & Games industry plugin (app/Modules/Industry/Toys). Two screens for
 * three menu rows: the age bands and the safety classes are one book to whoever
 * keeps them, so they share a component and are told apart by the route.
 *
 * There is no combo-pack or gift-wrap screen here on purpose — a toy shop's
 * packs and its wrap desk are the Gift & Novelty module's, which the toy-shop
 * preset ticks, and the Toy Shop menu group links straight to them.
 */
const ToyCertificatesView = () => import('../views/ToyCertificatesView.vue')
const ToyClassificationView = () => import('../views/ToyClassificationView.vue')
/**
 * Beauty & Personal Care industry plugin (app/Modules/Industry/Cosmetics). Two
 * screens; the shade master itself is a flat master on the generic CrudView from
 * config/resources.ts.
 *
 * There is no membership screen here on purpose — a beauty counter's club cards
 * are core Loyalty & Offers, which the preset ticks, and the Beauty Counter menu
 * group links straight to them.
 */
const ShadeCardView = () => import('../views/ShadeCardView.vue')
const CosmeticShelfLifeView = () => import('../views/CosmeticShelfLifeView.vue')
/**
 * Sports & Fitness industry plugin (app/Modules/Industry/Sports). Two screens for
 * three menu rows: the fleet register and the utilisation report are one book to
 * whoever keeps them, so they share a component and are told apart by the route —
 * the same shape the toy shop's classification screens use.
 *
 * There is no size-matrix, warranty or repair screen here on purpose. A sports
 * shop's size runs are the Retail plugin's variant matrix, its cover is core's
 * warranty card and its restringing is an ordinary service job — all three are
 * modules the sports-shop preset ticks, and the Sports Shop menu group links
 * straight to them.
 */
const SportsRentalDeskView = () => import('../views/SportsRentalDeskView.vue')
const SportsFleetView = () => import('../views/SportsFleetView.vue')
const CosmeticTestersView = () => import('../views/CosmeticTestersView.vue')
/**
 * Books & Periodicals industry plugin (app/Modules/Industry/BookStore). One
 * screen only: the publishers, the authors and the languages are flat masters
 * and run on the generic CrudView from config/resources.ts.
 *
 * Titles is not a master and cannot be one — a title *is* a product, so this
 * screen is a bookshop's way of reading the product list (by ISBN, house,
 * language and the people on the cover) plus the two things no CRUD form can
 * do: the ISBN lookup the counter uses, and the credits editor.
 */
const BookTitlesView = () => import('../views/BookTitlesView.vue')
// Automobile industry plugin (app/Modules/Industry/Automobile). There is no
// job-card screen here on purpose: a job card is a service job with a vehicle
// on it, so the Automobile menu's "Job Cards" row points at /service-jobs.
const AutomobileVehiclesView = () => import('../views/AutomobileVehiclesView.vue')
const AutomobileBookingsView = () => import('../views/AutomobileBookingsView.vue')
const AutomobileWarrantiesView = () => import('../views/AutomobileWarrantiesView.vue')
// Computer & IT industry plugin (app/Modules/Industry/ComputerIt). Same shape as
// Automobile above: there is no job-card screen here either, because a job card
// is a service job with a customer's machine on it — the Computer & IT menu's
// "Job Cards" row points at /service-jobs, and its "AMC Contracts" row at
// /service-contracts.
const ItAssetsView = () => import('../views/ItAssetsView.vue')
const ItWarrantiesView = () => import('../views/ItWarrantiesView.vue')
const ItLicenceKeysView = () => import('../views/ItLicenceKeysView.vue')
const ItServiceCallsView = () => import('../views/ItServiceCallsView.vue')
/**
 * Stationery industry plugin (app/Modules/Industry/Stationery). Same shape as the
 * two above: there is no school-order *document* screen here, because a school's
 * order is an ordinary sales order on the `institution` channel. The board below
 * is a projection over those orders, and its "open" action lands on
 * /sales-orders, which is where they are actually worked.
 */
const StationeryInstitutionsView = () => import('../views/StationeryInstitutionsView.vue')
const StationeryClassKitsView = () => import('../views/StationeryClassKitsView.vue')
const StationerySchoolOrdersView = () => import('../views/StationerySchoolOrdersView.vue')
const StationerySeasonDemandView = () => import('../views/StationerySeasonDemandView.vue')
const WarrantiesView = () => import('../views/WarrantiesView.vue')
const ElectronicsUnitsView = () => import('../views/ElectronicsUnitsView.vue')
const ElectronicsRequestsView = () => import('../views/ElectronicsRequestsView.vue')
/**
 * Mobile industry plugin (app/Modules/Industry/Mobile). Same shape as the two
 * above: no job-card screen, because a repair is a service job with a handset on
 * it, and no warranty screen, because cover is the core register at /warranties —
 * the Mobile menu's "Repair Jobs" and "Warranty Cards" rows point at both.
 */
const MobileHandsetsView = () => import('../views/MobileHandsetsView.vue')
const MobileInsuranceView = () => import('../views/MobileInsuranceView.vue')
const MobileEmiFilesView = () => import('../views/MobileEmiFilesView.vue')
const MobileBuybacksView = () => import('../views/MobileBuybacksView.vue')
const RestaurantFloorView = () => import('../views/RestaurantFloorView.vue')
const RestaurantOrdersView = () => import('../views/RestaurantOrdersView.vue')
const KitchenDisplayView = () => import('../views/KitchenDisplayView.vue')
const RestaurantTablesView = () => import('../views/RestaurantTablesView.vue')
// Food & Beverage industry plugin (app/Modules/Industry/FoodBeverage).
const RestaurantMenuView = () => import('../views/RestaurantMenuView.vue')
const RestaurantRecipesView = () => import('../views/RestaurantRecipesView.vue')
// Food & Beverage trades beyond the restaurant floor.
const TokenQueueView = () => import('../views/TokenQueueView.vue')
const MenuCombosView = () => import('../views/MenuCombosView.vue')
const HappyHoursView = () => import('../views/HappyHoursView.vue')
const CakeOrdersView = () => import('../views/CakeOrdersView.vue')
const MembershipsView = () => import('../views/MembershipsView.vue')
const MilkCollectionsView = () => import('../views/MilkCollectionsView.vue')
const MilkSubscriptionsView = () => import('../views/MilkSubscriptionsView.vue')
const MilkRoundView = () => import('../views/MilkRoundView.vue')
const ParlourFreezersView = () => import('../views/ParlourFreezersView.vue')
const WaiterPerformanceView = () => import('../views/WaiterPerformanceView.vue')
const QrMenuView = () => import('../views/QrMenuView.vue')
// Grocery & Kirana industry plugin (app/Modules/Industry/Grocery). Two screens
// the retail spine never needed: the pack that is one scan and three items off
// the shelf, and the shop's own khata with its distributors.
const ComboPacksView = () => import('../views/ComboPacksView.vue')
const SupplierCreditView = () => import('../views/SupplierCreditView.vue')
// The supermarket trade of the same vertical. One component serves the floor,
// the replenishment round and the planogram — three views of the same aisles,
// told apart by `meta.mode` rather than by three files that would each carry a
// copy of the aisle strip and the shelf picker.
const SupermarketShelvesView = () => import('../views/SupermarketShelvesView.vue')
const SelfCheckoutView = () => import('../views/SelfCheckoutView.vue')
// Core POS, not the supermarket module: any shop with two tills cashes up per
// desk. Same one-component-two-modes trick — the lanes and their shift history
// are the same objects from two ends.
const PosCountersView = () => import('../views/PosCountersView.vue')
// Distribution & Wholesale industry plugin (app/Modules/Industry/Distribution).
// Two screens the retail spine never needed: the party credit book, and
// receivables aged by how late they are.
const CreditControlView = () => import('../views/CreditControlView.vue')
const ReceivablesAgeingView = () => import('../views/ReceivablesAgeingView.vue')
// Agriculture industry plugin (app/Modules/Industry/Agriculture). The licence
// register belongs to the dealer half, the three farm screens to the other.
const AgriLicencesView = () => import('../views/AgriLicencesView.vue')
const FarmProductionView = () => import('../views/FarmProductionView.vue')
const LivestockAnimalsView = () => import('../views/LivestockAnimalsView.vue')
const LivestockFlocksView = () => import('../views/LivestockFlocksView.vue')
const EyePrescriptionsView = () => import('../views/EyePrescriptionsView.vue')
const OpticalOrdersView = () => import('../views/OpticalOrdersView.vue')
const OpticalAppointmentsView = () => import('../views/OpticalAppointmentsView.vue')
const OpticalDeliveryView = () => import('../views/OpticalDeliveryView.vue')
const MetalRatesView = () => import('../views/MetalRatesView.vue')
const OldGoldView = () => import('../views/OldGoldView.vue')
const PrescriptionsView = () => import('../views/PrescriptionsView.vue')
const DrugRegisterView = () => import('../views/DrugRegisterView.vue')
const LabTestsView = () => import('../views/LabTestsView.vue')
const LabOrdersView = () => import('../views/LabOrdersView.vue')
const HospitalIndentsView = () => import('../views/HospitalIndentsView.vue')
const HospitalConsumptionView = () => import('../views/HospitalConsumptionView.vue')
const ProjectsView = () => import('../views/ProjectsView.vue')
const ProjectTimesheetsView = () => import('../views/ProjectTimesheetsView.vue')
const OccasionsView = () => import('../views/OccasionsView.vue')
const WhatsAppView = () => import('../views/WhatsAppView.vue')
const SmsView = () => import('../views/SmsView.vue')
const MarketingCampaignsView = () => import('../views/MarketingCampaignsView.vue')
const EmailLogsView = () => import('../views/EmailLogsView.vue')
const SalesmanTargetsView = () => import('../views/SalesmanTargetsView.vue')
const BeatsView = () => import('../views/BeatsView.vue')
const SalesmanVisitsView = () => import('../views/SalesmanVisitsView.vue')
const SalesmanTrackingView = () => import('../views/SalesmanTrackingView.vue')
const SalesmanCollectionsView = () => import('../views/SalesmanCollectionsView.vue')
const SalesmanExpensesView = () => import('../views/SalesmanExpensesView.vue')
const SalesmanReportsView = () => import('../views/SalesmanReportsView.vue')
const SalesOrdersView = () => import('../views/SalesOrdersView.vue')
const SalesQuotationsView = () => import('../views/SalesQuotationsView.vue')
const PosView = () => import('../views/PosView.vue')
const SalesReturnsView = () => import('../views/SalesReturnsView.vue')
const B2bOrdersView = () => import('../views/B2bOrdersView.vue')
const EcommerceIntegrationsView = () => import('../views/EcommerceIntegrationsView.vue')
const ChannelOrdersView = () => import('../views/ChannelOrdersView.vue')
const ChannelListingsView = () => import('../views/ChannelListingsView.vue')
const ChannelSyncLogsView = () => import('../views/ChannelSyncLogsView.vue')
const BomsView = () => import('../views/BomsView.vue')
const ManufacturingOrdersView = () => import('../views/ManufacturingOrdersView.vue')
// Manufacturing industry plugin — the shop floor.
const MachinesView = () => import('../views/MachinesView.vue')
const MachineUtilisationView = () => import('../views/MachineUtilisationView.vue')
const QualityInspectionsView = () => import('../views/QualityInspectionsView.vue')
const ProductionScrapView = () => import('../views/ProductionScrapView.vue')
const JobWorksView = () => import('../views/JobWorksView.vue')
const FabricRollsView = () => import('../views/FabricRollsView.vue')
const FabricInspectionsView = () => import('../views/FabricInspectionsView.vue')
const ShrinkageRecordsView = () => import('../views/ShrinkageRecordsView.vue')
const FabricCuttingsView = () => import('../views/FabricCuttingsView.vue')
const ExpensesView = () => import('../views/ExpensesView.vue')
const EmployeeClaimsView = () => import('../views/EmployeeClaimsView.vue')
const VehicleExpensesView = () => import('../views/VehicleExpensesView.vue')
const FuelExpensesView = () => import('../views/FuelExpensesView.vue')
const ElectricityBillsView = () => import('../views/ElectricityBillsView.vue')
const FactoryMaintenanceView = () => import('../views/FactoryMaintenanceView.vue')
const AssetMaintenanceView = () => import('../views/AssetMaintenanceView.vue')
const DispatchPlansView = () => import('../views/DispatchPlansView.vue')
const ProofOfDeliveryView = () => import('../views/ProofOfDeliveryView.vue')
// Import & Export Logistics
const ImportExportDashboardView = () => import('../views/ImportExportDashboardView.vue')
const ShipmentsView = () => import('../views/ShipmentsView.vue')
const ShipmentTimelineView = () => import('../views/ShipmentTimelineView.vue')
const ShipmentEtaEtdView = () => import('../views/ShipmentEtaEtdView.vue')
const ShipmentDocumentsView = () => import('../views/ShipmentDocumentsView.vue')
const ShipmentCustomsView = () => import('../views/ShipmentCustomsView.vue')
const ShipmentPaymentsView = () => import('../views/ShipmentPaymentsView.vue')
const ShipmentContainersView = () => import('../views/ShipmentContainersView.vue')
const ShipmentProfitView = () => import('../views/ShipmentProfitView.vue')
const ShipmentExpensesView = () => import('../views/ShipmentExpensesView.vue')
const VouchersView = () => import('../views/VouchersView.vue')
const CashBookView = () => import('../views/CashBookView.vue')
const PettyCashView = () => import('../views/PettyCashView.vue')
const TrialBalanceView = () => import('../views/TrialBalanceView.vue')
const ProfitLossView = () => import('../views/ProfitLossView.vue')
const BalanceSheetView = () => import('../views/BalanceSheetView.vue')
const BankBookView = () => import('../views/BankBookView.vue')
const JournalView = () => import('../views/JournalView.vue')
const DayBookView = () => import('../views/DayBookView.vue')
const CustomerLedgerView = () => import('../views/CustomerLedgerView.vue')
const SupplierLedgerView = () => import('../views/SupplierLedgerView.vue')
const ExpenseLedgerView = () => import('../views/ExpenseLedgerView.vue')
const PriceListsView = () => import('../views/PriceListsView.vue')
const CycleCountsView = () => import('../views/CycleCountsView.vue')
const BatchesView = () => import('../views/BatchesView.vue')
const SerialNumbersView = () => import('../views/SerialNumbersView.vue')
const InventoryAlertsView = () => import('../views/InventoryAlertsView.vue')
const DeadStockView = () => import('../views/DeadStockView.vue')
const ReorderView = () => import('../views/ReorderView.vue')
const ReportsView = () => import('../views/ReportsView.vue')
const BarcodeScanView = () => import('../views/BarcodeScanView.vue')
const LabelPrintingView = () => import('../views/LabelPrintingView.vue')
const AuditLogsView = () => import('../views/AuditLogsView.vue')
const GstToolsView = () => import('../views/GstToolsView.vue')
const EInvoicesView = () => import('../views/EInvoicesView.vue')
const SearchResultsView = () => import('../views/SearchResultsView.vue')
const EWayBillsView = () => import('../views/EWayBillsView.vue')
const NotificationsView = () => import('../views/NotificationsView.vue')
const LeaveApplicationsView = () => import('../views/LeaveApplicationsView.vue')
const OvertimeView = () => import('../views/OvertimeView.vue')
const EmployeeBonusesView = () => import('../views/EmployeeBonusesView.vue')
const SalaryAdvancesView = () => import('../views/SalaryAdvancesView.vue')
const EmployeeLoansView = () => import('../views/EmployeeLoansView.vue')
const BiometricPunchesView = () => import('../views/BiometricPunchesView.vue')
const DeviceManagerView = () => import('../views/DeviceManagerView.vue')
const DeviceEventsView = () => import('../views/DeviceEventsView.vue')
const PunchKioskView = () => import('../views/PunchKioskView.vue')
const PayrollView = () => import('../views/PayrollView.vue')
const CustomerDisplayView = () => import('../views/CustomerDisplayView.vue')
const PaymentGatewaysView = () => import('../views/PaymentGatewaysView.vue')
const PaymentTransactionsView = () => import('../views/PaymentTransactionsView.vue')
const PayView = () => import('../views/PayView.vue')
const AiInsightsView = () => import('../views/AiInsightsView.vue')
const AiAssistantView = () => import('../views/AiAssistantView.vue')

// Single-domain app. Public routes (login + self-serve signup / verification /
// provisioning status) sit alongside the authenticated tenant application; the
// tenant is resolved server-side from the logged-in user, not from the host.
const routes: RouteRecordRaw[] = [
  { path: '/login', name: 'login', component: LoginView, meta: { public: true } },
  // Hard-locked workspace (suspended by SaaS operator) — reachable while
  // authenticated; the http interceptor redirects here on 403 tenant_suspended.
  { path: '/suspended', name: 'suspended', component: SuspendedView, meta: { public: true } },
  { path: '/register', name: 'register', component: CentralRegisterView, meta: { public: true } },
  { path: '/verify', name: 'central-verify', component: CentralVerifyView, meta: { public: true } },
  { path: '/provisioning', name: 'central-provisioning', component: CentralProvisioningView, meta: { public: true } },
  {
    path: '/',
    component: AppLayout,
    meta: { requiresAuth: true },
    children: [
      { path: '', name: 'dashboard', component: DashboardView },
      // Where the guard below lands a screen this workspace is not granted.
      { path: 'access-denied', name: 'access-denied', component: AccessDeniedView },
      { path: 'billing', name: 'billing', component: BillingView },
      // Global omni-search results (§22). No permission gate: the API itself
      // only returns groups the caller is allowed to view.
      { path: 'search', name: 'search', component: SearchResultsView },
      { path: 'notifications', component: NotificationsView },
      { path: 'products', component: ProductsView, meta: { permission: 'products.view' } },
      { path: 'categories', component: CategoriesView, meta: { permission: 'categories.view' } },
      { path: 'brands', component: CrudView, meta: { resource: 'brands', permission: 'brands.view' } },
      { path: 'units', component: CrudView, meta: { resource: 'units', permission: 'units.view' } },
      { path: 'warehouses', component: WarehousesView, meta: { permission: 'warehouses.view' } },
      { path: 'suppliers', component: SuppliersView, meta: { permission: 'suppliers.view' } },
      { path: 'customers', component: CustomersView, meta: { permission: 'customers.view' } },
      { path: 'customer-categories', component: CustomerCategoriesView, meta: { permission: 'customer-categories.view' } },
      { path: 'price-lists', component: PriceListsView, meta: { permission: 'price-lists.view' } },
      { path: 'brokers/ledger', component: BrokerLedgerView, meta: { permission: 'brokers.view' } },
      { path: 'brokers', component: CrudView, meta: { resource: 'brokers', permission: 'brokers.view' } },
      { path: 'leads', component: LeadsView, meta: { permission: 'leads.view' } },
      { path: 'follow-ups', component: FollowUpsView, meta: { permission: 'follow-ups.view' } },
      { path: 'coupons', component: CrudView, meta: { resource: 'coupons', permission: 'coupons.view' } },
      { path: 'loyalty', component: LoyaltyView, meta: { permission: 'loyalty.view' } },
      { path: 'gift-vouchers', component: GiftVouchersView, meta: { permission: 'gift-vouchers.view' } },

      /**
       * Gift & Novelty. The wrap desk, hamper packs and the occasion board; the
       * wrapping master rides on the shared CrudView from config/resources.ts.
       *
       * Note the permissions differ from the menu's in one place on purpose:
       * building a batch of hampers needs `stock.create`, which the pack screen
       * checks itself — the *screen* is merchandising and only needs
       * `gift-packs.view` to open.
       */
      { path: 'gift/wraps', component: GiftWrapDeskView, meta: { permission: 'gift-wraps.view' } },
      { path: 'gift/wrap-options', component: CrudView, meta: { resource: 'gift-wrap-options', permission: 'gift-wraps.view' } },
      { path: 'gift/packs', component: GiftPacksView, meta: { permission: 'gift-packs.view' } },
      { path: 'gift/occasions', component: GiftOccasionsView, meta: { permission: 'gift-occasions.view' } },

      /**
       * Toys & Games. Two routes onto one component for the classification half,
       * the same way the warranty register and its claims share a screen: both
       * menu rows have to land on the right tab.
       */
      { path: 'toys/certificates', component: ToyCertificatesView, meta: { permission: 'toy-safety.view' } },
      { path: 'toys/safety-classes', component: ToyClassificationView, meta: { permission: 'toy-safety.view' } },
      { path: 'toys/age-grades', component: ToyClassificationView, meta: { permission: 'toy-safety.view' } },

      /**
       * Beauty & Personal Care. The shade card opens on `cosmetic-shades.view`
       * even though generating one needs `products.create` as well — the screen
       * is a stock question a buyer asks all day, and the button that creates
       * variants hides itself when the seat cannot use it.
       */
      { path: 'cosmetics/shelf-life', component: CosmeticShelfLifeView, meta: { permission: 'cosmetic-shelf-life.view' } },
      { path: 'cosmetics/testers', component: CosmeticTestersView, meta: { permission: 'cosmetic-shelf-life.view' } },
      { path: 'cosmetics/shades', component: CrudView, meta: { resource: 'cosmetic-shades', permission: 'cosmetic-shades.view' } },
      { path: 'cosmetics/shade-card', component: ShadeCardView, meta: { permission: 'cosmetic-shades.view' } },

      /**
       * Sports & Fitness. Two routes onto one component for the fleet half, told
       * apart inside it by the path — the register and the utilisation report are
       * the same book read two ways.
       *
       * The desk opens on `rentals.view` even though settling a hire needs
       * `sales.create` as well: the board is what a counter watches all day, and
       * the button that raises the bill hides itself when the seat cannot use it.
       */
      { path: 'sports/rentals', component: SportsRentalDeskView, meta: { permission: 'rentals.view' } },
      { path: 'sports/fleet', component: SportsFleetView, meta: { permission: 'rental-assets.view' } },
      { path: 'sports/utilisation', component: SportsFleetView, meta: { permission: 'rental-assets.view' } },

      /**
       * Books & Periodicals. One permission across all four, because the four
       * screens are one book to whoever keeps it — see the note in the plugin's
       * routes.php. There is no genre screen here on purpose: a genre is a core
       * category, and the Book Store menu links straight to Categories.
       */
      { path: 'books/titles', component: BookTitlesView, meta: { permission: 'book-catalogue.view' } },
      { path: 'books/authors', component: CrudView, meta: { resource: 'book-authors', permission: 'book-catalogue.view' } },
      { path: 'books/publishers', component: CrudView, meta: { resource: 'book-publishers', permission: 'book-catalogue.view' } },
      { path: 'books/languages', component: CrudView, meta: { resource: 'book-languages', permission: 'book-catalogue.view' } },

      /**
       * Retail — the garment trade. The four masters run on the shared CrudView
       * from config/resources.ts; only the grid and the trial room need screens
       * of their own, because neither is a list of records edited one at a time.
       */
      { path: 'garment/styles', component: CrudView, meta: { resource: 'garment-styles', permission: 'garment-styles.view' } },
      { path: 'garment/fabrics', component: CrudView, meta: { resource: 'garment-fabrics', permission: 'garment-fabrics.view' } },
      { path: 'garment/size-sets', component: CrudView, meta: { resource: 'garment-size-sets', permission: 'garment-styles.view' } },
      { path: 'garment/colours', component: CrudView, meta: { resource: 'garment-colours', permission: 'garment-styles.view' } },
      { path: 'garment/matrix', component: GarmentMatrixView, meta: { permission: 'products.view' } },
      { path: 'garment/trials', component: GarmentTrialsView, meta: { permission: 'garment-trials.view' } },
      { path: 'service-jobs', component: ServiceJobsView, meta: { permission: 'service-jobs.view' } },
      { path: 'service-contracts', component: ServiceContractsView, meta: { permission: 'service-contracts.view' } },
      // Retail warranty. Two routes, one component: the register and its claim
      // book are one screen with two tabs, and both menu rows have to land on
      // the right one.
      { path: 'warranties/claims', component: WarrantiesView, meta: { permission: 'warranty-claims.view' } },
      { path: 'warranties', component: WarrantiesView, meta: { permission: 'warranties.view' } },
      { path: 'automobile/vehicles', component: AutomobileVehiclesView, meta: { permission: 'automobile-vehicles.view' } },
      { path: 'automobile/bookings', component: AutomobileBookingsView, meta: { permission: 'automobile-bookings.view' } },
      { path: 'automobile/warranties', component: AutomobileWarrantiesView, meta: { permission: 'automobile-warranties.view' } },
      // Electronics & Appliances. No warranty route of its own — cover is the
      // core register at /warranties, which the Electronics nav group links to.
      { path: 'electronics/requests', component: ElectronicsRequestsView, meta: { permission: 'electronics-requests.view' } },
      { path: 'electronics/units', component: ElectronicsUnitsView, meta: { permission: 'electronics-units.view' } },
      { path: 'computer-it/service-calls', component: ItServiceCallsView, meta: { permission: 'it-service-calls.view' } },
      { path: 'computer-it/assets', component: ItAssetsView, meta: { permission: 'it-assets.view' } },
      { path: 'computer-it/warranties', component: ItWarrantiesView, meta: { permission: 'it-warranties.view' } },
      { path: 'computer-it/licence-keys', component: ItLicenceKeysView, meta: { permission: 'it-licences.view' } },
      // Stationery. The school-orders board reads core sales orders, so it gates
      // on sales.view rather than on a stationery permission — the rows *are*
      // sales orders, and hiding them here but not on /sales-orders would be a
      // difference without a meaning.
      { path: 'stationery/class-kits', component: StationeryClassKitsView, meta: { permission: 'stationery-kits.view' } },
      { path: 'stationery/institutions', component: StationeryInstitutionsView, meta: { permission: 'stationery-institutions.view' } },
      { path: 'stationery/school-orders', component: StationerySchoolOrdersView, meta: { permission: 'sales.view' } },
      { path: 'stationery/season-demand', component: StationerySeasonDemandView, meta: { permission: 'stationery-kits.view' } },
      // Mobile. No warranty route of its own for the same reason as Electronics
      // above, and no repair route either — both are second doors from the Mobile
      // nav group onto /warranties and /service-jobs.
      { path: 'mobile/handsets', component: MobileHandsetsView, meta: { permission: 'mobile-handsets.view' } },
      { path: 'mobile/insurance', component: MobileInsuranceView, meta: { permission: 'mobile-insurance.view' } },
      { path: 'mobile/emi', component: MobileEmiFilesView, meta: { permission: 'mobile-emi.view' } },
      { path: 'mobile/buybacks', component: MobileBuybacksView, meta: { permission: 'mobile-buyback.view' } },
      { path: 'restaurant/floor', component: RestaurantFloorView, meta: { permission: 'restaurant-tables.view' } },
      { path: 'restaurant/orders', component: RestaurantOrdersView, meta: { permission: 'restaurant-orders.view' } },
      { path: 'restaurant/kitchen', component: KitchenDisplayView, meta: { permission: 'kitchen-orders.view' } },
      { path: 'restaurant/tables', component: RestaurantTablesView, meta: { permission: 'restaurant-tables.edit' } },
      { path: 'restaurant/menu', component: RestaurantMenuView, meta: { permission: 'restaurant-menu.view' } },
      { path: 'restaurant/recipes', component: RestaurantRecipesView, meta: { permission: 'restaurant-recipes.view' } },
      // Food & Beverage trades. The token board rides on the kitchen's
      // permission because it is worked by whoever calls the numbers.
      { path: 'restaurant/tokens', component: TokenQueueView, meta: { permission: 'kitchen-orders.view' } },
      { path: 'restaurant/combos', component: MenuCombosView, meta: { permission: 'menu-combos.view' } },
      { path: 'restaurant/happy-hours', component: HappyHoursView, meta: { permission: 'menu-happy-hours.view' } },
      { path: 'cake-orders', component: CakeOrdersView, meta: { permission: 'cake-orders.view' } },
      // Both membership paths land on the same tabbed screen: the tiers and the
      // cards are one job, and the nav offers two ways in.
      { path: 'memberships', component: MembershipsView, meta: { permission: 'memberships.view' } },
      { path: 'membership-plans', component: MembershipsView, meta: { permission: 'memberships.view' } },
      { path: 'milk-collections', component: MilkCollectionsView, meta: { permission: 'milk-collections.view' } },
      { path: 'milk-subscriptions', component: MilkSubscriptionsView, meta: { permission: 'milk-subscriptions.view' } },
      { path: 'milk-round', component: MilkRoundView, meta: { permission: 'milk-round.view' } },
      { path: 'parlour/freezers', component: ParlourFreezersView, meta: { permission: 'stock.view' } },
      { path: 'reports/waiter-performance', component: WaiterPerformanceView, meta: { permission: 'reports.view' } },
      // Grocery & Kirana. Combo packs sit on `products.view` because a pack is a
      // product with a statement of what it breaks into; the khata has its own
      // grant because it is the owner's book of debts.
      { path: 'grocery/combo-packs', component: ComboPacksView, meta: { permission: 'products.view' } },
      { path: 'grocery/supplier-credit', component: SupplierCreditView, meta: { permission: 'supplier-credit.view' } },
      /**
       * Supermarket. The floor and the round read stock and so sit on
       * `stock.view`; the planogram is merchandising and has its own grant,
       * because the person who walks the round is not the person who decides
       * that the biscuits move to aisle four.
       */
      { path: 'supermarket/shelves', component: SupermarketShelvesView, meta: { permission: 'stock.view', mode: 'floor' } },
      { path: 'supermarket/replenishment', component: SupermarketShelvesView, meta: { permission: 'stock.view', mode: 'round' } },
      { path: 'supermarket/planogram', component: SupermarketShelvesView, meta: { permission: 'shelf-planogram.view', mode: 'layout' } },
      { path: 'supermarket/self-checkout', component: SelfCheckoutView, meta: { permission: 'self-checkout.view' } },
      /**
       * Tills and cash-up. Under `pos-counters`, not `supermarket` — a jeweller
       * with a separate silver counter reconciles per desk exactly as a
       * hypermarket does. Both routes are on `.view`: a cashier who refreshed
       * the page has to be able to find out which shift they are on.
       */
      { path: 'pos-counters', component: PosCountersView, meta: { permission: 'pos-counters.view', mode: 'counters' } },
      { path: 'pos-counters/sessions', component: PosCountersView, meta: { permission: 'pos-counters.view', mode: 'sessions' } },
      // Distribution & Wholesale. The ageing report keeps its own route rather
      // than becoming a ReportsView tab because it is a work list with its own
      // filters and a per-party drill-down, not a table with a date range. Both
      // must stay above the `reports/:tab?` catch-all further down.
      { path: 'credit-control', component: CreditControlView, meta: { permission: 'credit-control.view' } },
      { path: 'reports/receivables-ageing', component: ReceivablesAgeingView, meta: { permission: 'reports.view' } },
      { path: 'agri-licences', component: AgriLicencesView, meta: { permission: 'agri-licences.view' } },
      { path: 'livestock/production', component: FarmProductionView, meta: { permission: 'farm-production.view' } },
      { path: 'livestock/animals', component: LivestockAnimalsView, meta: { permission: 'livestock-animals.view' } },
      { path: 'livestock/flocks', component: LivestockFlocksView, meta: { permission: 'livestock-flocks.view' } },
      { path: 'eye-prescriptions', component: EyePrescriptionsView, meta: { permission: 'eye-prescriptions.view' } },
      { path: 'optical-orders', component: OpticalOrdersView, meta: { permission: 'optical-orders.view' } },
      { path: 'optical-appointments', component: OpticalAppointmentsView, meta: { permission: 'optical-appointments.view' } },
      // The collection desk works the same orders the workshop screen does, so
      // it sits on their permission rather than one of its own.
      { path: 'optical-delivery', component: OpticalDeliveryView, meta: { permission: 'optical-orders.view' } },
      { path: 'metal-rates', component: MetalRatesView, meta: { permission: 'metal-rates.view' } },
      { path: 'old-gold', component: OldGoldView, meta: { permission: 'old-gold.view' } },
      { path: 'prescriptions', component: PrescriptionsView, meta: { permission: 'prescriptions.view' } },
      { path: 'drug-register', component: DrugRegisterView, meta: { permission: 'drug-register.view' } },
      { path: 'lab-orders', component: LabOrdersView, meta: { permission: 'lab-orders.view' } },
      { path: 'lab-tests', component: LabTestsView, meta: { permission: 'lab-tests.view' } },
      { path: 'hospital-indents', component: HospitalIndentsView, meta: { permission: 'hospital-indents.view' } },
      { path: 'hospital-consumption', component: HospitalConsumptionView, meta: { permission: 'hospital-indents.view' } },
      { path: 'projects', component: ProjectsView, meta: { permission: 'projects.view' } },
      { path: 'project-timesheets', component: ProjectTimesheetsView, meta: { permission: 'project-timesheets.view' } },
      { path: 'referrals', component: ReferralsView, meta: { permission: 'referrals.view' } },
      { path: 'crm/occasions', component: OccasionsView, meta: { permission: 'customers.view' } },
      { path: 'whatsapp', component: WhatsAppView, meta: { permission: 'whatsapp.view' } },
      { path: 'sms', component: SmsView, meta: { permission: 'sms.view' } },
      { path: 'marketing-campaigns', component: MarketingCampaignsView, meta: { permission: 'marketing-campaigns.view' } },
      { path: 'email-logs', component: EmailLogsView, meta: { permission: 'email.view' } },
      { path: 'salesmen', component: CrudView, meta: { resource: 'salesmen', permission: 'salesmen.view' } },
      { path: 'salesman-targets', component: SalesmanTargetsView, meta: { permission: 'salesman-targets.view' } },
      { path: 'beats', component: BeatsView, meta: { permission: 'beats.view' } },
      { path: 'salesman-visits', component: SalesmanVisitsView, meta: { permission: 'salesman-visits.view' } },
      { path: 'salesman-tracking', component: SalesmanTrackingView, meta: { permission: 'salesman-locations.view' } },
      { path: 'salesman-collections', component: SalesmanCollectionsView, meta: { permission: 'salesman-collections.view' } },
      { path: 'salesman-expenses', component: SalesmanExpensesView, meta: { permission: 'salesman-expenses.view' } },
      { path: 'salesman-reports', component: SalesmanReportsView, meta: { permission: 'salesmen.view', module: 'field_sales' } },
      { path: 'tailors', component: CrudView, meta: { resource: 'tailors', permission: 'tailors.view' } },
      { path: 'contractors', component: CrudView, meta: { resource: 'contractors', permission: 'contractors.view' } },
      { path: 'rate-cards', component: CrudView, meta: { resource: 'rate-cards', permission: 'rate-cards.view' } },
      { path: 'piece-rates', component: CrudView, meta: { resource: 'piece-rates', permission: 'piece-rates.view' } },
      { path: 'tailor-works/pending', component: TailorWorkView, meta: { workMode: 'pending', permission: 'tailor-works.view' } },
      { path: 'tailor-works/completed', component: TailorWorkView, meta: { workMode: 'completed', permission: 'tailor-works.view' } },
      { path: 'tailor-alterations', component: CrudView, meta: { resource: 'tailor-alterations', permission: 'tailor-alterations.view' } },
      { path: 'tailor-bonuses', component: TailorBonusView, meta: { permission: 'tailor-bonuses.view' } },
      { path: 'tailor-performance', component: TailorPerformanceView, meta: { permission: 'reports.view', module: 'tailoring' } },
      { path: 'tailor-payments', component: TailorPaymentView, meta: { permission: 'tailor-payments.view' } },
      { path: 'tailors/ledger', component: TailorLedgerView, meta: { permission: 'tailors.view' } },
      { path: 'embroidery-vendors', component: CrudView, meta: { resource: 'embroidery-vendors', permission: 'embroidery-vendors.view' } },
      { path: 'embroidery-works/pending', component: EmbroideryWorkView, meta: { workMode: 'pending', permission: 'embroidery-works.view' } },
      { path: 'embroidery-works/received', component: EmbroideryWorkView, meta: { workMode: 'completed', permission: 'embroidery-works.view' } },
      { path: 'embroidery-payments', component: EmbroideryPaymentView, meta: { permission: 'embroidery-payments.view' } },
      { path: 'embroidery-vendors/ledger', component: EmbroideryLedgerView, meta: { permission: 'embroidery-vendors.view' } },
      { path: 'purchase-indents', component: PurchaseIndentsView, meta: { permission: 'purchase-indents.view' } },
      { path: 'rfqs', component: RfqsView, meta: { permission: 'rfqs.view' } },
      { path: 'purchase-orders', component: PurchaseOrdersView, meta: { permission: 'purchases.view' } },
      { path: 'vendor-ratings', component: VendorRatingsView, meta: { permission: 'vendor-ratings.view' } },
      { path: 'purchase-invoices', component: PurchaseInvoicesView, meta: { permission: 'purchase-invoices.view' } },
      { path: 'sales-quotations', component: SalesQuotationsView, meta: { permission: 'sales.view' } },
      { path: 'sales-orders', component: SalesOrdersView, meta: { permission: 'sales.view' } },
      { path: 'pos', component: PosView, meta: { permission: 'sales.create' } },
      { path: 'sales-returns', component: SalesReturnsView, meta: { permission: 'sales-returns.view' } },
      { path: 'b2b-orders', component: B2bOrdersView, meta: { permission: 'b2b-orders.view' } },
      { path: 'ecommerce-integrations', component: EcommerceIntegrationsView, meta: { permission: 'ecommerce-integrations.view' } },
      { path: 'channel-orders', component: ChannelOrdersView, meta: { permission: 'channel-orders.view' } },
      { path: 'channel-listings', component: ChannelListingsView, meta: { permission: 'channel-listings.view' } },
      { path: 'channel-sync-logs', component: ChannelSyncLogsView, meta: { permission: 'channel-sync-logs.view' } },
      { path: 'boms', component: BomsView, meta: { permission: 'boms.view' } },
      { path: 'manufacturing-orders', component: ManufacturingOrdersView, meta: { permission: 'manufacturing-orders.view' } },
      { path: 'colour-printings', component: CrudView, meta: { resource: 'colour-printings', permission: 'colour-printing.view' } },
      { path: 'job-works', component: JobWorksView, meta: { permission: 'job-works.view' } },
      // Shop floor. 'machines/utilisation' is declared before 'machines/:id'
      // would be — there is no such route today, but keeping the literal first
      // means adding one later cannot silently swallow it.
      { path: 'machines/utilisation', component: MachineUtilisationView, meta: { permission: 'machines.view' } },
      { path: 'machines', component: MachinesView, meta: { permission: 'machines.view' } },
      { path: 'quality-inspections', component: QualityInspectionsView, meta: { permission: 'quality-inspections.view' } },
      { path: 'production-scraps', component: ProductionScrapView, meta: { permission: 'production-scrap.view' } },
      { path: 'fabric-rolls', component: FabricRollsView, meta: { permission: 'textile.view' } },
      { path: 'fabric-inspections', component: FabricInspectionsView, meta: { permission: 'textile.view' } },
      { path: 'shrinkage-records', component: ShrinkageRecordsView, meta: { permission: 'textile.view' } },
      { path: 'fabric-cuttings', component: FabricCuttingsView, meta: { permission: 'textile.view' } },
      { path: 'expenses', component: ExpensesView, meta: { permission: 'expenses.view' } },
      { path: 'expense-categories', component: CrudView, meta: { resource: 'expense-categories', permission: 'expense-categories.view' } },
      { path: 'employee-claims', component: EmployeeClaimsView, meta: { permission: 'employee-claims.view' } },
      { path: 'vehicle-expenses', component: VehicleExpensesView, meta: { permission: 'vehicle-expenses.view' } },
      { path: 'fuel-expenses', component: FuelExpensesView, meta: { permission: 'fuel-expenses.view' } },
      { path: 'electricity-bills', component: ElectricityBillsView, meta: { permission: 'electricity-bills.view' } },
      { path: 'factory-maintenances', component: FactoryMaintenanceView, meta: { permission: 'factory-maintenances.view' } },
      { path: 'asset-maintenances', component: AssetMaintenanceView, meta: { permission: 'asset-maintenances.view' } },
      { path: 'vehicles', component: CrudView, meta: { resource: 'vehicles', permission: 'vehicles.view' } },
      { path: 'assets', component: CrudView, meta: { resource: 'assets', permission: 'assets.view' } },
      // Transport & Logistics
      { path: 'drivers', component: CrudView, meta: { resource: 'drivers', permission: 'drivers.view' } },
      { path: 'delivery-routes', component: CrudView, meta: { resource: 'delivery-routes', permission: 'delivery-routes.view' } },
      { path: 'dispatch-plans', component: DispatchPlansView, meta: { permission: 'dispatch-plans.view' } },
      { path: 'proof-of-delivery', component: ProofOfDeliveryView, meta: { permission: 'dispatch-pods.view' } },
      { path: 'transport-charges', component: CrudView, meta: { resource: 'transport-charges', permission: 'transport-charges.view' } },
      // Import & Export Logistics — masters
      { path: 'countries', component: CrudView, meta: { resource: 'countries', permission: 'countries.view' } },
      { path: 'ports', component: CrudView, meta: { resource: 'ports', permission: 'ports.view' } },
      { path: 'shipping-lines', component: CrudView, meta: { resource: 'shipping-lines', permission: 'shipping-lines.view' } },
      { path: 'airlines', component: CrudView, meta: { resource: 'airlines', permission: 'airlines.view' } },
      { path: 'freight-forwarders', component: CrudView, meta: { resource: 'freight-forwarders', permission: 'freight-forwarders.view' } },
      { path: 'cha-agents', component: CrudView, meta: { resource: 'cha-agents', permission: 'cha-agents.view' } },
      { path: 'transporters', component: CrudView, meta: { resource: 'transporters', permission: 'transporters.view' } },
      { path: 'overseas-suppliers', component: CrudView, meta: { resource: 'overseas-suppliers', permission: 'suppliers.view' } },
      { path: 'overseas-customers', component: CrudView, meta: { resource: 'overseas-customers', permission: 'customers.view' } },
      // Import & Export Logistics — shipments. The two shipment lists share one
      // view; `direction` meta picks the leg.
      { path: 'import-export', component: ImportExportDashboardView, meta: { permission: 'shipments.view', module: 'logistics' } },
      { path: 'import-export/import-shipments', component: ShipmentsView, meta: { permission: 'shipments.view', module: 'logistics', direction: 'import' } },
      { path: 'import-export/export-shipments', component: ShipmentsView, meta: { permission: 'shipments.view', module: 'logistics', direction: 'export' } },
      { path: 'import-export/timeline', component: ShipmentTimelineView, meta: { permission: 'shipments.view', module: 'logistics' } },
      { path: 'import-export/eta-etd', component: ShipmentEtaEtdView, meta: { permission: 'shipments.view', module: 'logistics' } },
      { path: 'import-export/documents', component: ShipmentDocumentsView, meta: { permission: 'shipment-documents.view', module: 'logistics' } },
      { path: 'import-export/customs', component: ShipmentCustomsView, meta: { permission: 'shipment-customs.view', module: 'logistics' } },
      { path: 'import-export/payments', component: ShipmentPaymentsView, meta: { permission: 'shipment-payments.view', module: 'logistics' } },
      { path: 'import-export/containers', component: ShipmentContainersView, meta: { permission: 'shipment-containers.view', module: 'logistics' } },
      { path: 'import-export/profit', component: ShipmentProfitView, meta: { permission: 'shipments.view', module: 'logistics' } },
      { path: 'import-export/expenses', component: ShipmentExpensesView, meta: { permission: 'shipment-expenses.view', module: 'logistics' } },
      { path: 'bank-accounts', component: CrudView, meta: { resource: 'bank-accounts', permission: 'bank-accounts.view' } },
      { path: 'vouchers', component: VouchersView, meta: { permission: 'vouchers.view' } },
      { path: 'accounts/cash-book', component: CashBookView, meta: { permission: 'accounts.view' } },
      { path: 'accounts/bank-book', component: BankBookView, meta: { permission: 'accounts.view' } },
      { path: 'accounts/journal', component: JournalView, meta: { permission: 'accounts.view' } },
      { path: 'accounts/day-book', component: DayBookView, meta: { permission: 'accounts.view' } },
      { path: 'accounts/petty-cash', component: PettyCashView, meta: { permission: 'accounts.view' } },
      { path: 'accounts/trial-balance', component: TrialBalanceView, meta: { permission: 'accounts.view', module: 'accounts' } },
      { path: 'accounts/profit-loss', component: ProfitLossView, meta: { permission: 'accounts.view', module: 'accounts' } },
      { path: 'accounts/balance-sheet', component: BalanceSheetView, meta: { permission: 'accounts.view', module: 'accounts' } },
      { path: 'tds-tcs-rates', component: CrudView, meta: { resource: 'tds-tcs-rates', permission: 'tds-tcs-rates.view' } },
      { path: 'accounts/customer-ledger', component: CustomerLedgerView, meta: { permission: 'customers.view' } },
      { path: 'accounts/supplier-ledger', component: SupplierLedgerView, meta: { permission: 'suppliers.view' } },
      { path: 'accounts/expense-ledger', component: ExpenseLedgerView, meta: { permission: 'expenses.view' } },
      { path: 'stock', component: StockView, meta: { permission: 'stock.view' } },
      { path: 'stock-ledger', component: StockLedgerView, meta: { permission: 'stock-ledger.view' } },
      { path: 'cycle-counts', component: CycleCountsView, meta: { permission: 'cycle-counts.view' } },
      { path: 'stock-adjustments', component: StockAdjustmentsView, meta: { permission: 'stock-adjustments.view' } },
      { path: 'stock-transfers', component: StockTransfersView, meta: { permission: 'stock-transfers.view' } },
      { path: 'stock-returns', component: StockReturnsView, meta: { permission: 'stock-returns.view' } },
      { path: 'gate-entries', component: GateEntriesView, meta: { permission: 'gate-entries.view' } },
      { path: 'batches', component: BatchesView, meta: { permission: 'advanced-inventory.view' } },
      { path: 'serial-numbers', component: SerialNumbersView, meta: { permission: 'advanced-inventory.view' } },
      { path: 'inventory-alerts', component: InventoryAlertsView, meta: { permission: 'advanced-inventory.view' } },
      { path: 'dead-stock', component: DeadStockView, meta: { permission: 'advanced-inventory.view', module: 'inventory' } },
      { path: 'reorder', component: ReorderView, meta: { permission: 'advanced-inventory.view' } },
      { path: 'reports/:tab?', component: ReportsView, meta: { permission: 'reports.view' } },
      { path: 'barcode-scan', component: BarcodeScanView, meta: { permission: 'barcodes.view' } },
      { path: 'label-printing', component: LabelPrintingView, meta: { permission: 'barcodes.view' } },
      { path: 'departments', component: CrudView, meta: { resource: 'departments', permission: 'departments.view' } },
      { path: 'employees', component: CrudView, meta: { resource: 'employees', permission: 'employees.view' } },
      { path: 'attendance', component: CrudView, meta: { resource: 'attendance', permission: 'attendance.view' } },
      { path: 'salaries', component: CrudView, meta: { resource: 'salaries', permission: 'salaries.view' } },
      { path: 'leave-types', component: CrudView, meta: { resource: 'leave-types', permission: 'leave-types.view' } },
      { path: 'leave-applications', component: LeaveApplicationsView, meta: { permission: 'leave-applications.view' } },
      { path: 'overtime', component: OvertimeView, meta: { permission: 'overtime.view' } },
      { path: 'employee-bonuses', component: EmployeeBonusesView, meta: { permission: 'employee-bonuses.view' } },
      { path: 'salary-advances', component: SalaryAdvancesView, meta: { permission: 'salary-advances.view' } },
      { path: 'employee-loans', component: EmployeeLoansView, meta: { permission: 'employee-loans.view' } },
      { path: 'salary-structures', component: CrudView, meta: { resource: 'salary-structures', permission: 'salary-structures.view' } },
      { path: 'biometric-devices', component: CrudView, meta: { resource: 'biometric-devices', permission: 'biometric-devices.view' } },
      { path: 'biometric-punches', component: BiometricPunchesView, meta: { permission: 'biometric-punches.view' } },
      // Device Manager (plugin-based hardware layer). Untagged by module on
      // purpose — hardware spans POS, inventory and HR.
      { path: 'devices', component: DeviceManagerView, meta: { permission: 'devices.view' } },
      { path: 'device-events', component: DeviceEventsView, meta: { permission: 'device-events.view' } },
      { path: 'punch-kiosk', component: PunchKioskView, meta: { permission: 'biometric-punches.create' } },
      { path: 'payroll', component: PayrollView, meta: { permission: 'payroll.view' } },
      { path: 'users', component: UsersView, meta: { permission: 'users.view' } },
      { path: 'roles', component: RolesView, meta: { permission: 'roles.view' } },
      // Own profile — no permission gate; every signed-in user has one.
      { path: 'profile', name: 'profile', component: () => import('../views/ProfileView.vue') },
      { path: 'settings', component: SettingsView, meta: { permission: 'settings.view' } },
      { path: 'system-tools', component: () => import('../views/SystemToolsView.vue'), meta: { permission: 'settings.view' } },
      // The field builder. Untagged by module: it is how a trade the module
      // catalogue does not name makes the masters its own.
      { path: 'custom-fields', component: () => import('../views/CustomFieldsView.vue'), meta: { permission: 'custom-fields.view' } },
      { path: 'printer-test', component: () => import('../views/PrinterTestView.vue'), meta: { permission: 'settings.view' } },
      { path: 'workflows', component: () => import('../views/WorkflowsView.vue'), meta: { permission: 'workflows.view' } },
      { path: 'audit-logs', component: AuditLogsView, meta: { permission: 'audit-logs.view' } },
      { path: 'companies', component: CrudView, meta: { resource: 'companies', permission: 'companies.view' } },
      { path: 'branches', component: CrudView, meta: { resource: 'branches', permission: 'branches.view' } },
      // Payment Gateways (§16)
      { path: 'payment-gateways', component: PaymentGatewaysView, meta: { permission: 'payment-gateways.view' } },
      { path: 'payment-transactions', component: PaymentTransactionsView, meta: { permission: 'payments.view' } },
      // GST Integration (§20)
      { path: 'gst-registrations', component: CrudView, meta: { resource: 'gst-registrations', permission: 'gst-registrations.view' } },
      { path: 'hsn-codes', component: CrudView, meta: { resource: 'hsn-codes', permission: 'hsn-codes.view' } },
      { path: 'gst-tools', component: GstToolsView, meta: { permission: 'hsn-codes.view' } },
      { path: 'e-invoices', component: EInvoicesView, meta: { permission: 'e-invoices.view' } },
      { path: 'e-way-bills', component: EWayBillsView, meta: { permission: 'e-way-bills.view' } },
      // AI Features (§22)
      { path: 'ai-assistant', component: AiAssistantView, meta: { permission: 'ai.view' } },
      { path: 'ai-insights', component: AiInsightsView, meta: { permission: 'ai.view' } },
    ],
  },
  // Full-screen POS customer-facing display (own window on a 2nd monitor).
  // Outside AppLayout: no chrome, mirrors the terminal over a BroadcastChannel.
  { path: '/pos-display', name: 'pos-display', component: CustomerDisplayView, meta: { requiresAuth: true } },

  // Public hosted pay page (SRS §16): customers open a payment link here — no
  // login. Outside AppLayout; the tenant is derived server-side from the token.
  { path: '/pay/:token', name: 'pay', component: PayView, meta: { public: true } },

  // Public QR menu (Food & Beverage plugin): a guest scans the sticker on their
  // table. No login, outside AppLayout, and read-only — the tenant and table are
  // derived server-side from the token. There is deliberately no ordering route.
  { path: '/menu/:token', name: 'qr-menu', component: QrMenuView, meta: { public: true } },

  // B2B customer portal (SRS §14): a separate authenticated surface for
  // dealers/distributors/retailers, gated by its own portal token.
  { path: '/portal/login', name: 'portal-login', component: PortalLoginView, meta: { portal: true, portalPublic: true } },
  {
    path: '/portal',
    component: PortalLayout,
    meta: { portal: true },
    children: [
      { path: '', name: 'portal-catalogue', component: PortalCatalogueView },
      { path: 'price-list', name: 'portal-price-list', component: PortalPriceListView },
      { path: 'cart', name: 'portal-cart', component: PortalCartView },
      { path: 'orders', name: 'portal-orders', component: PortalOrdersView },
    ],
  },
  // SaaS-owner platform panel (Level-1 RBAC): platform operators managing the
  // SaaS itself — tenants, billing, support, ops. Own token/guard, separate
  // from staff and portal. Authenticates against /api/central/*.
  { path: '/platform/login', name: 'central-login', component: CentralLoginView, meta: { central: true, centralPublic: true } },
  {
    path: '/platform',
    component: CentralAdminLayout,
    meta: { central: true },
    children: [
      { path: '', name: 'central-dashboard', component: CentralDashboardView },
      { path: 'tenants', name: 'central-tenants', component: CentralTenantsView, meta: { permission: 'tenants.view' } },
      { path: 'industries', name: 'central-industries', component: CentralIndustriesView, meta: { permission: 'industries.view' } },
      { path: 'menus', name: 'central-menus', component: CentralMenusView, meta: { permission: 'menus.view' } },
      // Shop-wise menu templates — same permission as the catalogue above,
      // because both decide what a workspace is created with.
      { path: 'shop-menus', name: 'central-shop-menus', component: CentralShopMenusView, meta: { permission: 'menus.view' } },
      { path: 'plans', name: 'central-plans', component: CentralPlansView, meta: { permission: 'plans.view' } },
      { path: 'billing', name: 'central-billing', component: CentralBillingView, meta: { permission: 'subscriptions.view' } },
      // The marketplace shell renders whichever tab the operator is entitled to,
      // so either permission is enough to open it.
      { path: 'marketplace', name: 'central-marketplace', component: CentralMarketplaceView, meta: { permission: ['integrations.view', 'devices.view'] } },
      { path: 'usage', name: 'central-usage', component: CentralUsageView, meta: { permission: 'usage.view' } },
      { path: 'analytics', name: 'central-analytics', component: CentralAnalyticsView, meta: { permission: 'analytics.view' } },
      { path: 'insights', name: 'central-insights', component: CentralInsightsView, meta: { permission: 'insights.view' } },
      { path: 'operators', name: 'central-operators', component: CentralOperatorsView, meta: { permission: 'operators.view' } },
      { path: 'roles', name: 'central-roles', component: CentralRolesView, meta: { permission: 'roles.view' } },
      { path: 'ops', name: 'central-ops', component: CentralOpsView, meta: { permission: 'ops-health.view' } },
      { path: 'support', name: 'central-support', component: CentralSupportView, meta: { permission: 'support.view' } },
    ],
  },
  { path: '/:pathMatch(.*)*', redirect: '/' },
]

const router = createRouter({
  history: createWebHistory(),
  routes,
})

// Drive the global top progress bar around each navigation so lazy route-chunk
// fetches (see the dynamic imports above) show visible feedback instead of a
// frozen screen. finish() runs on success, redirect, and failure alike.
const routeProgress = useRouteProgress()
router.beforeEach((to, from) => {
  if (to.path !== from.path) routeProgress.start()
})
router.afterEach(() => routeProgress.finish())
router.onError(() => routeProgress.finish())

router.beforeEach(async (to) => {
  // SaaS-owner platform panel has its own token/session, separate from staff.
  if (to.meta.central) {
    const central = useCentralAuthStore()
    if (to.meta.centralPublic) {
      return central.isAuthenticated && to.name === 'central-login' ? { name: 'central-dashboard' } : true
    }
    if (!central.isAuthenticated) {
      return { name: 'central-login' }
    }
    if (!central.operator) {
      try {
        await central.fetchMe()
      } catch {
        return { name: 'central-login' }
      }
    }
    // An array means "any of these" — for screens like the Marketplace whose
    // tabs are permissioned separately and which are worth opening if the
    // operator holds even one of them.
    if (to.meta.permission) {
      const required = to.meta.permission as string | string[]
      const allowed = Array.isArray(required)
        ? required.some((p) => central.can(p))
        : central.can(required)
      if (!allowed) return { name: 'central-dashboard' }
    }
    return true
  }

  // B2B customer portal has its own token/session, separate from staff.
  if (to.meta.portal) {
    const portal = usePortalAuthStore()
    if (to.meta.portalPublic) {
      return portal.isAuthenticated && to.name === 'portal-login' ? { name: 'portal-catalogue' } : true
    }
    if (!portal.isAuthenticated) {
      return { name: 'portal-login' }
    }
    if (!portal.customer) {
      try {
        await portal.fetchMe()
      } catch {
        return { name: 'portal-login' }
      }
    }
    return true
  }

  const auth = useAuthStore()

  if (to.meta.public) {
    return auth.isAuthenticated && to.name === 'login' ? { path: '/' } : true
  }

  if (!auth.isAuthenticated) {
    return { name: 'login' }
  }

  // Ensure the user profile (roles/permissions) is loaded before guarding.
  if (!auth.user) {
    try {
      await auth.fetchUser()
    } catch (e) {
      // Workspace hard-locked by the SaaS operator: every API call (incl.
      // /me) answers 403 tenant_suspended — park the session on the
      // suspended screen instead of bouncing to login.
      const resp = (e as { response?: { status?: number; data?: { error?: string } } }).response
      if (resp?.status === 403 && resp.data?.error === 'tenant_suspended') {
        return { name: 'suspended' }
      }
      return { name: 'login' }
    }
  }

  if (to.meta.permission && !auth.can(to.meta.permission as string)) {
    return { path: '/' }
  }

  // Module gating (onboarding wizard §3): a screen tagged with `module` belongs to
  // a switchable slice of the product, so it is off-limits when the workspace did
  // not enable it — the same rule the sidebar and the API middleware apply.
  if (to.meta.module) {
    const needed = Array.isArray(to.meta.module) ? (to.meta.module as string[]) : [to.meta.module as string]
    if (!auth.hasModule(...needed)) {
      return { path: '/' }
    }
  }

  // Menu-level access. The two checks above work off permissions and module tags,
  // which cannot decide every screen on their own: POS has no permissions of its
  // own and rides on `sales.*`, so with Sales switched off the Sales screens still
  // pass a `sales.view` check. /menu is the authority — it already applies module,
  // plan-feature and permission gating per row — and it now also reports the
  // catalogue screens it withheld. Anything on that list is refused here, so a
  // hidden nav row cannot be reached by typed URL, bookmark or back button.
  if (to.name !== 'access-denied') {
    const menu = useMenuStore()
    if (!menu.loaded) {
      try {
        await menu.fetchMenu()
      } catch {
        // Menu unavailable (offline, API error): fail open rather than lock the
        // user out of the whole app on a transient failure.
      }
    }
    if (menu.loaded && menu.isRouteDenied(to.path)) {
      return { name: 'access-denied', query: { from: to.path } }
    }
  }

  return true
})

export default router
