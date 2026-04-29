import AllCoupons from "./components/All_Licenses.vue";
import translate from "@/utils/translator/Translator";

window.fluent_cart_admin.hooks.addFilter(
  "fluent_cart_routes",
  "fluent_all_coupons",
  function (routes) {
    routes.product_route.children.push({
      name: "coupon_settings",
      path: "license_settings",
      props: true,
      component: LicenseSettings,
      meta: {
        active_menu: "products",
        title: translate("License Settings"),
      },
    });

    routes.coupon_route = {
      name: "coupons",
      path: "/coupons",
      component: AllCoupons,
      props: true,
      meta: {
        active_menu: "coupons",
        title: "Coupons",
      },
    };
    return routes;
  }
);
