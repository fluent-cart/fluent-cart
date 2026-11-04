import translate from "@/utils/translator/Translator";

// Channel names, shared by the Sources channels card and the Overview
export const channelLabels = () => ({
  email: translate('Email'),
  paid: translate('Paid ads'),
  social: translate('Social'),
  ai: translate('AI assistants'),
  search: translate('Search'),
  referral: translate('Other websites'),
  campaign: translate('Other campaigns'),
  direct: translate('Direct or unknown'),
});
