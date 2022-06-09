import { DateRangePicker } from 'vanillajs-datepicker';

import './styles.css';

window.addEventListener('load', () => {
  const elem = document.getElementById('report-range');
  new DateRangePicker(elem, {
    // @ts-ignore
    format: 'dd.mm.yyyy',
  });
});



