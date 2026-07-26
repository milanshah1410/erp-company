// Administration / settings / reports / AI screens.
// Populated as those views are converted to pt(); until then their English
// text renders as-is via the pt() fallback.
import type { PhraseFile } from './types'

const phrases: PhraseFile = {
  // --- My Profile ---
  'My Profile': { hi: 'मेरी प्रोफ़ाइल', gu: 'મારી પ્રોફાઇલ' },
  'Your name, contact details, photo and password.': {
    hi: 'आपका नाम, संपर्क विवरण, फ़ोटो और पासवर्ड।',
    gu: 'તમારું નામ, સંપર્ક વિગતો, ફોટો અને પાસવર્ડ.',
  },
  'Finish setting up your profile — add a phone number and a photo so your team recognises you.': {
    hi: 'अपनी प्रोफ़ाइल पूरी करें — फ़ोन नंबर और फ़ोटो जोड़ें ताकि आपकी टीम आपको पहचान सके।',
    gu: 'તમારી પ્રોફાઇલ પૂર્ણ કરો — ફોન નંબર અને ફોટો ઉમેરો જેથી તમારી ટીમ તમને ઓળખે.',
  },
  'Personal details': { hi: 'व्यक्तिगत विवरण', gu: 'વ્યક્તિગત વિગતો' },
  'Full name': { hi: 'पूरा नाम', gu: 'પૂરું નામ' },
  'Upload photo': { hi: 'फ़ोटो अपलोड करें', gu: 'ફોટો અપલોડ કરો' },
  'Change photo': { hi: 'फ़ोटो बदलें', gu: 'ફોટો બદલો' },
  'Photo updated': { hi: 'फ़ोटो अपडेट हुई', gu: 'ફોટો અપડેટ થયો' },
  'Photo upload failed': { hi: 'फ़ोटो अपलोड विफल', gu: 'ફોટો અપલોડ નિષ્ફળ' },
  'JPG/PNG up to 2MB.': { hi: 'JPG/PNG, 2MB तक।', gu: 'JPG/PNG, 2MB સુધી.' },
  'This is your login. Changing it needs re-verification.': {
    hi: 'यही आपका लॉगिन है। इसे बदलने पर पुनः सत्यापन आवश्यक है।',
    gu: 'આ તમારું લૉગિન છે. બદલવા પર ફરી ખાતરી કરવી પડશે.',
  },
  'Save changes': { hi: 'बदलाव सहेजें', gu: 'ફેરફારો સાચવો' },
  'Profile updated': { hi: 'प्रोफ़ाइल अपडेट हुई', gu: 'પ્રોફાઇલ અપડેટ થઈ' },
  'Could not load your profile': { hi: 'आपकी प्रोफ़ाइल लोड नहीं हो सकी', gu: 'તમારી પ્રોફાઇલ લોડ થઈ શકી નથી' },
  'Change password': { hi: 'पासवर्ड बदलें', gu: 'પાસવર્ડ બદલો' },
  'Current password': { hi: 'वर्तमान पासवर्ड', gu: 'હાલનો પાસવર્ડ' },
  'New password': { hi: 'नया पासवर्ड', gu: 'નવો પાસવર્ડ' },
  'Confirm new password': { hi: 'नया पासवर्ड पुष्टि करें', gu: 'નવો પાસવર્ડ ખાતરી કરો' },
  'Minimum 8 characters.': { hi: 'कम से कम 8 अक्षर।', gu: 'ઓછામાં ઓછા 8 અક્ષર.' },
  'Password changed': { hi: 'पासवर्ड बदल दिया गया', gu: 'પાસવર્ડ બદલાયો' },
  'Your access': { hi: 'आपकी पहुँच', gu: 'તમારો ઍક્સેસ' },
  'Email verified': { hi: 'ईमेल सत्यापित', gu: 'ઈમેલ ખાતરી થયેલ' },
  'Company details (GSTIN, address, financial year) live under': {
    hi: 'कंपनी विवरण (GSTIN, पता, वित्तीय वर्ष) यहाँ हैं:',
    gu: 'કંપની વિગતો (GSTIN, સરનામું, નાણાકીય વર્ષ) આ હેઠળ છે:',
  },
}

export default phrases
