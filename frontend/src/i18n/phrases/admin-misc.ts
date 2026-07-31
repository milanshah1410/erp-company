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

  // PrinterTestView
  'Printer Testing': { hi: 'प्रिंटर टेस्टिंग', gu: 'પ્રિન્ટર ટેસ્ટિંગ' },
  'Send a sample print of each template to check paper size, margins and alignment. Test prints are never saved as documents.': {
    hi: 'कागज़ का आकार, मार्जिन और अलाइनमेंट जाँचने के लिए हर टेम्पलेट का सैंपल प्रिंट भेजें। टेस्ट प्रिंट कभी दस्तावेज़ के रूप में सहेजे नहीं जाते।',
    gu: 'કાગળનું માપ, માર્જિન અને અલાઇનમેન્ટ ચકાસવા દરેક ટેમ્પ્લેટની સેમ્પલ પ્રિન્ટ મોકલો. ટેસ્ટ પ્રિન્ટ ક્યારેય દસ્તાવેજ તરીકે સાચવાતી નથી.',
  },
  'A4 Invoice': { hi: 'A4 इनवॉइस', gu: 'A4 ઇન્વૉઇસ' },
  'Thermal Invoice': { hi: 'थर्मल इनवॉइस', gu: 'થર્મલ ઇન્વૉઇસ' },
  'Barcode Label': { hi: 'बारकोड लेबल', gu: 'બારકોડ લેબલ' },
  'Full-page tax invoice on a laser/inkjet printer.': {
    hi: 'लेज़र/इंकजेट प्रिंटर पर पूरे पेज का टैक्स इनवॉइस।',
    gu: 'લેઝર/ઇંકજેટ પ્રિન્ટર પર આખા પાનાનું ટેક્સ ઇન્વૉઇસ.',
  },
  '58/80 mm POS receipt via browser or ESC/POS agent.': {
    hi: 'ब्राउज़र या ESC/POS एजेंट द्वारा 58/80 मि.मी. POS रसीद।',
    gu: 'બ્રાઉઝર અથવા ESC/POS એજન્ટ દ્વારા 58/80 મિ.મી. POS રસીદ.',
  },
  'Label sheet for the barcode/label printer.': {
    hi: 'बारकोड/लेबल प्रिंटर के लिए लेबल शीट।',
    gu: 'બારકોડ/લેબલ પ્રિન્ટર માટે લેબલ શીટ.',
  },
  'Prints the same tax-invoice template real invoices use, filled with sample data.': {
    hi: 'असली इनवॉइस वाला ही टैक्स-इनवॉइस टेम्पलेट, सैंपल डेटा के साथ प्रिंट होता है।',
    gu: 'સાચાં ઇન્વૉઇસ વાપરે છે તે જ ટેક્સ-ઇન્વૉઇસ ટેમ્પ્લેટ, સેમ્પલ ડેટા સાથે પ્રિન્ટ થાય છે.',
  },
  'Paper Size': { hi: 'कागज़ का आकार', gu: 'કાગળનું માપ' },
  'A4 (210 × 297 mm)': { hi: 'A4 (210 × 297 मि.मी.)', gu: 'A4 (210 × 297 મિ.મી.)' },
  'Letter (216 × 279 mm)': { hi: 'लेटर (216 × 279 मि.मी.)', gu: 'લેટર (216 × 279 મિ.મી.)' },
  'A5 (148 × 210 mm)': { hi: 'A5 (148 × 210 मि.मी.)', gu: 'A5 (148 × 210 મિ.મી.)' },
  'Sample Line Items': { hi: 'सैंपल लाइन आइटम', gu: 'સેમ્પલ લાઇન આઇટમ' },
  'Raise this past a page to test page breaks and the repeated footer.': {
    hi: 'पेज ब्रेक और हर पेज पर आने वाला फुटर जाँचने के लिए इसे एक पेज से ज़्यादा रखें।',
    gu: 'પેજ બ્રેક અને દરેક પાના પર આવતું ફૂટર ચકાસવા આને એક પાનાથી વધારે રાખો.',
  },
  "These are this terminal's printer settings — POS billing prints with exactly the same ones.": {
    hi: 'ये इसी टर्मिनल की प्रिंटर सेटिंग्स हैं — POS बिलिंग भी बिल्कुल इन्हीं से प्रिंट करती है।',
    gu: 'આ આ જ ટર્મિનલની પ્રિન્ટર સેટિંગ્સ છે — POS બિલિંગ પણ બરાબર આ જ થી પ્રિન્ટ કરે છે.',
  },
  'Test Print': { hi: 'टेस्ट प्रिंट', gu: 'ટેસ્ટ પ્રિન્ટ' },
  'Test Cash Drawer': { hi: 'कैश ड्रॉअर जाँचें', gu: 'કેશ ડ્રોઅર ચકાસો' },
  'Save Settings': { hi: 'सेटिंग्स सहेजें', gu: 'સેટિંગ્સ સાચવો' },
  'Reset to defaults': { hi: 'डिफ़ॉल्ट पर लौटाएँ', gu: 'ડિફૉલ્ટ પર પાછું લાવો' },
  'Printer settings saved': { hi: 'प्रिंटर सेटिंग्स सहेजी गईं', gu: 'પ્રિન્ટર સેટિંગ્સ સાચવાઈ' },
  'Sent to print agent': { hi: 'प्रिंट एजेंट को भेजा गया', gu: 'પ્રિન્ટ એજન્ટને મોકલ્યું' },
  'Drawer kick needs the local print agent.': {
    hi: 'ड्रॉअर खोलने के लिए लोकल प्रिंट एजेंट चाहिए।',
    gu: 'ડ્રોઅર ખોલવા માટે લોકલ પ્રિન્ટ એજન્ટ જરૂરી છે.',
  },
  'PRINTER TEST': { hi: 'प्रिंटर टेस्ट', gu: 'પ્રિન્ટર ટેસ્ટ' },
  'Prints a sheet of identical sample labels so label pitch and cut lines can be checked.': {
    hi: 'एक जैसे सैंपल लेबलों की शीट प्रिंट करता है, ताकि लेबल की दूरी और कट लाइन जाँची जा सके।',
    gu: 'સરખાં સેમ્પલ લેબલની શીટ પ્રિન્ટ કરે છે, જેથી લેબલનું અંતર અને કટ લાઇન ચકાસી શકાય.',
  },
  'Label Format': { hi: 'लेबल फ़ॉर्मेट', gu: 'લેબલ ફૉર્મેટ' },
  'Sample Code Value': { hi: 'सैंपल कोड वैल्यू', gu: 'સેમ્પલ કોડ વેલ્યુ' },
  'Number of Labels': { hi: 'लेबलों की संख्या', gu: 'લેબલની સંખ્યા' },
  'Could not generate the test print.': {
    hi: 'टेस्ट प्रिंट नहीं बन सका।',
    gu: 'ટેસ્ટ પ્રિન્ટ બની શકી નથી.',
  },
  'Hit Preview to render the template here, or Print to open it in a new tab.': {
    hi: 'टेम्पलेट यहीं देखने के लिए प्रीव्यू दबाएँ, या नए टैब में खोलने के लिए प्रिंट।',
    gu: 'ટેમ્પ્લેટ અહીં જ જોવા માટે પ્રીવ્યૂ દબાવો, અથવા નવા ટૅબમાં ખોલવા માટે પ્રિન્ટ.',
  },
}

export default phrases
