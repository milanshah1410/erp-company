// Toys & Games plugin phrases: the age bands, the safety classes and the
// certificate register behind them.
import type { PhraseFile } from './types'

const phrases: PhraseFile = {
  // ---- Classification screen ----
  'Toy Classification': { hi: 'खिलौना वर्गीकरण', gu: 'રમકડાં વર્ગીકરણ' },
  'Who each toy is for, and what is dangerous about it': {
    hi: 'हर खिलौना किसके लिए है, और उसमें खतरा क्या है',
    gu: 'દરેક રમકડું કોના માટે છે, અને તેમાં જોખમ શું છે',
  },
  'Add Age Band': { hi: 'आयु वर्ग जोड़ें', gu: 'ઉંમર જૂથ ઉમેરો' },
  'Add Safety Class': { hi: 'सुरक्षा श्रेणी जोड़ें', gu: 'સલામતી વર્ગ ઉમેરો' },
  'Edit Age Band': { hi: 'आयु वर्ग संपादित करें', gu: 'ઉંમર જૂથ ફેરફાર કરો' },
  'Edit Safety Class': { hi: 'सुरक्षा श्रेणी संपादित करें', gu: 'સલામતી વર્ગ ફેરફાર કરો' },
  'Band': { hi: 'वर्ग', gu: 'જૂથ' },
  'Range': { hi: 'सीमा', gu: 'મર્યાદા' },
  'Class': { hi: 'श्रेणी', gu: 'વર્ગ' },
  'Standard': { hi: 'मानक', gu: 'ધોરણ' },
  'Hazards': { hi: 'खतरे', gu: 'જોખમો' },
  'Articles': { hi: 'वस्तुएँ', gu: 'વસ્તુઓ' },
  'Article': { hi: 'वस्तु', gu: 'વસ્તુ' },
  'Not below': { hi: 'इससे कम नहीं', gu: 'આનાથી ઓછું નહીં' },
  'Uncertified': { hi: 'बिना प्रमाणपत्र', gu: 'પ્રમાણપત્ર વગર' },
  'Refuses the sale': { hi: 'बिक्री रोक देगा', gu: 'વેચાણ રોકશે' },
  'Warns only': { hi: 'केवल चेतावनी', gu: 'ફક્ત ચેતવણી' },
  'No certificate needed': { hi: 'प्रमाणपत्र आवश्यक नहीं', gu: 'પ્રમાણપત્રની જરૂર નથી' },
  'Adult supervision': { hi: 'वयस्क निगरानी', gu: 'પુખ્ત દેખરેખ' },
  'Needs adult supervision': { hi: 'वयस्क निगरानी आवश्यक', gu: 'પુખ્ત દેખરેખ જરૂરી' },
  'Needs a safety certificate': { hi: 'सुरक्षा प्रमाणपत्र आवश्यक', gu: 'સલામતી પ્રમાણપત્ર જરૂરી' },
  'Refuse the sale when the certificate is missing or lapsed': {
    hi: 'प्रमाणपत्र न होने या समाप्त होने पर बिक्री रोकें',
    gu: 'પ્રમાણપત્ર ન હોય કે સમાપ્ત થયું હોય તો વેચાણ રોકો',
  },
  'What the label says': { hi: 'लेबल पर क्या लिखा है', gu: 'લેબલ પર શું લખ્યું છે' },
  'From (months)': { hi: 'से (महीने)', gu: 'થી (મહિના)' },
  'To (months)': { hi: 'तक (महीने)', gu: 'સુધી (મહિના)' },
  'Not below (months)': { hi: 'इससे कम नहीं (महीने)', gu: 'આનાથી ઓછું નહીં (મહિના)' },
  'month': { hi: 'महीना', gu: 'મહિનો' },
  'months': { hi: 'महीने', gu: 'મહિના' },
  'year': { hi: 'साल', gu: 'વર્ષ' },
  'years': { hi: 'साल', gu: 'વર્ષ' },
  'In use': { hi: 'उपयोग में', gu: 'ઉપયોગમાં' },
  'Retired': { hi: 'बंद', gu: 'બંધ' },
  'Age band saved': { hi: 'आयु वर्ग सहेजा गया', gu: 'ઉંમર જૂથ સાચવ્યું' },
  'Safety class saved': { hi: 'सुरक्षा श्रेणी सहेजी गई', gu: 'સલામતી વર્ગ સાચવ્યો' },
  'Could not save the age band': { hi: 'आयु वर्ग सहेजा नहीं जा सका', gu: 'ઉંમર જૂથ સાચવી શકાયું નહીં' },
  'Could not save the safety class': {
    hi: 'सुरक्षा श्रेणी सहेजी नहीं जा सकी',
    gu: 'સલામતી વર્ગ સાચવી શકાયો નહીં',
  },
  'Delete this age band?': { hi: 'यह आयु वर्ग हटाएँ?', gu: 'આ ઉંમર જૂથ કાઢી નાખવું?' },
  'Delete this safety class?': { hi: 'यह सुरक्षा श्रेणी हटाएँ?', gu: 'આ સલામતી વર્ગ કાઢી નાખવો?' },
  'Only bands with no articles graded into them can be deleted.': {
    hi: 'केवल वे वर्ग हटाए जा सकते हैं जिनमें कोई वस्तु नहीं है।',
    gu: 'ફક્ત તે જૂથ કાઢી શકાય જેમાં કોઈ વસ્તુ ન હોય.',
  },
  'Only classes with no articles under them can be deleted.': {
    hi: 'केवल वे श्रेणियाँ हटाई जा सकती हैं जिनमें कोई वस्तु नहीं है।',
    gu: 'ફક્ત તે વર્ગ કાઢી શકાય જેમાં કોઈ વસ્તુ ન હોય.',
  },

  // ---- Product editor ----
  'Age Band': { hi: 'आयु वर्ग', gu: 'ઉંમર જૂથ' },
  'Safety Class': { hi: 'सुरक्षा श्रेणी', gu: 'સલામતી વર્ગ' },
  'Not graded by age': { hi: 'आयु वर्ग तय नहीं', gu: 'ઉંમર જૂથ નક્કી નથી' },
  'No specific hazard': { hi: 'कोई विशेष खतरा नहीं', gu: 'કોઈ ખાસ જોખમ નથી' },
  'Decides the warning at the till — and, for a class that needs one, whether a lapsed certificate stops the sale.': {
    hi: 'काउंटर पर चेतावनी तय करता है — और जिस श्रेणी को प्रमाणपत्र चाहिए, उसमें समाप्त प्रमाणपत्र बिक्री रोक देगा।',
    gu: 'કાઉન્ટર પર ચેતવણી નક્કી કરે છે — અને જે વર્ગને પ્રમાણપત્ર જોઈએ, તેમાં સમાપ્ત પ્રમાણપત્ર વેચાણ રોકશે.',
  },

  // ---- Certificate register ----
  'The licences and test reports behind what you are allowed to sell': {
    hi: 'जो आप बेच सकते हैं उसके पीछे के लाइसेंस और परीक्षण रिपोर्ट',
    gu: 'તમે જે વેચી શકો છો તેની પાછળના લાઇસન્સ અને પરીક્ષણ અહેવાલો',
  },
  'File Certificate': { hi: 'प्रमाणपत्र दर्ज करें', gu: 'પ્રમાણપત્ર દાખલ કરો' },
  'Edit Certificate': { hi: 'प्रमाणपत्र संपादित करें', gu: 'પ્રમાણપત્ર ફેરફાર કરો' },
  'Certificate': { hi: 'प्रमाणपत्र', gu: 'પ્રમાણપત્ર' },
  'Certificate number': { hi: 'प्रमाणपत्र संख्या', gu: 'પ્રમાણપત્ર નંબર' },
  'Manufacturer licence no.': { hi: 'निर्माता लाइसेंस नं.', gu: 'ઉત્પાદક લાઇસન્સ નં.' },
  'Covers': { hi: 'किसके लिए', gu: 'કોના માટે' },
  'covers': { hi: 'किसके लिए', gu: 'કોના માટે' },
  'Issued by': { hi: 'जारीकर्ता', gu: 'જારી કરનાર' },
  'Issued on': { hi: 'जारी तिथि', gu: 'જારી તારીખ' },
  'Expires on': { hi: 'समाप्ति तिथि', gu: 'સમાપ્તિ તારીખ' },
  'Expires': { hi: 'समाप्त', gu: 'સમાપ્ત' },
  'Open-ended': { hi: 'बिना समय-सीमा', gu: 'સમય મર્યાદા વગર' },
  'On file': { hi: 'दर्ज', gu: 'નોંધાયેલ' },
  'Live today': { hi: 'आज वैध', gu: 'આજે માન્ય' },
  'Expired': { hi: 'समाप्त', gu: 'સમાપ્ત' },
  'Withdrawn': { hi: 'वापस लिया गया', gu: 'પાછું ખેંચ્યું' },
  'Withdraw': { hi: 'वापस लें', gu: 'પાછું ખેંચો' },
  'Scanned document': { hi: 'स्कैन दस्तावेज़', gu: 'સ્કેન દસ્તાવેજ' },
  'Path to the stored scan': { hi: 'संग्रहित स्कैन का पथ', gu: 'સંગ્રહિત સ્કેનનો પાથ' },
  'Certificate saved': { hi: 'प्रमाणपत्र सहेजा गया', gu: 'પ્રમાણપત્ર સાચવ્યું' },
  'Could not save the certificate': {
    hi: 'प्रमाणपत्र सहेजा नहीं जा सका',
    gu: 'પ્રમાણપત્ર સાચવી શકાયું નહીં',
  },
  'Withdraw this certificate?': { hi: 'यह प्रमाणपत्र वापस लें?', gu: 'આ પ્રમાણપત્ર પાછું ખેંચવું?' },
  'Everything it covers stops being certified from now on. The record stays for the audit trail.': {
    hi: 'इसके अंतर्गत सब कुछ अब प्रमाणित नहीं रहेगा। रिकॉर्ड ऑडिट के लिए बना रहेगा।',
    gu: 'તેની હેઠળનું બધું હવે પ્રમાણિત રહેશે નહીં. રેકોર્ડ ઓડિટ માટે રહેશે.',
  },
  'Delete this certificate?': { hi: 'यह प्रमाणपत्र हटाएँ?', gu: 'આ પ્રમાણપત્ર કાઢી નાખવું?' },
  'Withdrawing keeps the record; deleting removes it entirely.': {
    hi: 'वापस लेने पर रिकॉर्ड रहता है; हटाने पर पूरी तरह मिट जाता है।',
    gu: 'પાછું ખેંચવાથી રેકોર્ડ રહે છે; કાઢી નાખવાથી સંપૂર્ણ મટી જાય છે.',
  },

  // ---- The counter question ----
  'May we sell this?': { hi: 'क्या यह बेच सकते हैं?', gu: 'શું આ વેચી શકાય?' },
  'Article name or SKU': { hi: 'वस्तु का नाम या SKU', gu: 'વસ્તુનું નામ કે SKU' },
  'Certified and covered today.': { hi: 'आज प्रमाणित और वैध है।', gu: 'આજે પ્રમાણિત અને માન્ય છે.' },
  'This class needs no certificate.': {
    hi: 'इस श्रेणी को प्रमाणपत्र की आवश्यकता नहीं।',
    gu: 'આ વર્ગને પ્રમાણપત્રની જરૂર નથી.',
  },
  'The certificate behind this article has lapsed. The sale is allowed, but the paperwork is not.': {
    hi: 'इस वस्तु का प्रमाणपत्र समाप्त हो चुका है। बिक्री हो सकती है, कागज़ात नहीं चलेंगे।',
    gu: 'આ વસ્તુનું પ્રમાણપત્ર સમાપ્ત થયું છે. વેચાણ થઈ શકે, પણ કાગળ ચાલશે નહીં.',
  },
  'No certificate is on file for this article.': {
    hi: 'इस वस्तु के लिए कोई प्रमाणपत्र दर्ज नहीं है।',
    gu: 'આ વસ્તુ માટે કોઈ પ્રમાણપત્ર નોંધાયું નથી.',
  },
  'The till will refuse this article — its certificate is not in order.': {
    hi: 'काउंटर यह वस्तु नहीं बेचेगा — इसका प्रमाणपत्र ठीक नहीं है।',
    gu: 'કાઉન્ટર આ વસ્તુ વેચશે નહીં — તેનું પ્રમાણપત્ર બરાબર નથી.',
  },
}

export default phrases
