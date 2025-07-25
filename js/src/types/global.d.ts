declare global {
  var wpApiSettings: {
    root: string
    nonce: string
  }
  var appData: {
    env: {
      siteUrl: string
      ajaxUrl: string
      ajaxNonce: string
      userId: string
      postId: string
      permalink: string
      APP_NAME: string
      KEBAB: string
      SNAKE: string
      BASE_URL: string
      APP1_SELECTOR: string
      APP2_SELECTOR: string
      API_TIMEOUT: string
    }
  }
  var wp: {
    blocks: any
    element: any
    htmlEntities: any
    i18n: any
  }
  var wc: {
    wcBlocksRegistry: any
    wcSettings: any
  }
}

// WooCommerce 和 WordPress 模組型別定義
declare module '@woocommerce/settings' {
  export function getSetting(name: string, defaultValue?: any): any
  export const wcSettings: any
  export default wcSettings
}

declare module '@woocommerce/blocks-registry' {
  export const wcBlocksRegistry: any
  export default wcBlocksRegistry
}

export {}
